#!/usr/bin/env bash
#
# store-proof.sh: asserts, against a running store, what this module claims
# and no unit test can reach.
#
# The store proof runs this after installing the module the way somebody else
# would, enabling it and running setup:upgrade twice. It is given
# STORE_PROOF_MAGENTO, STORE_PROOF_SQL, STORE_PROOF_PHP, STORE_PROOF_MODULE and
# STORE_PROOF_STORE. A probe that cannot run is a failure, never a pass.
#
# It needs a store with a replica the store's own user can read and ask for
# its replication status, and it stops, saying which is missing, when there is
# none. STORE_PROOF_REPLICA_HOST names it (db-replica by default), and
# STORE_PROOF_FPM the PHP-FPM socket the probe is run through
# (unix:///run/php/fpm.sock by default).
#
# The module only splits a storefront request, never the command line, so the
# probe runs under PHP-FPM as a GET. Which server answered is counted on the
# servers themselves: each one's Com_select before and after a batch of plain
# reads through the store's own connection.
#
# It writes the db/read_split block into app/etc/env.php, three ways, puts the
# file back as it found it, and asks PHP-FPM to drop the copy it holds, since a
# store that does not re-read changed files would go on routing by the last
# block written. It does not assert read-after-write across two requests, which
# rides a cookie only a full page response sets.

set -euo pipefail

MODULE_NAME="Kingletas_ReadSplit"
PROBE_FILE="store-proof-read-split.php"
PROBE="${STORE_PROOF_STORE}/local.d/${PROBE_FILE}"
ENV_FILE="${STORE_PROOF_STORE}/app/etc/env.php"
ENV_KEPT="${STORE_PROOF_STORE}/local.d/store-proof-read-split.env.php"
REPLICA_HOST="${STORE_PROOF_REPLICA_HOST:-db-replica}"
# A host name nothing answers to, for the fallback: .invalid never resolves.
DEAD_HOST="store-proof-read-split.invalid"
FPM="${STORE_PROOF_FPM:-unix:///run/php/fpm.sock}"
# Plain reads per batch, and how many of them the other server may also count: the store's own bootstrap and
# anything else running on it read too, and that must not pass for a routing decision.
READS=200
NOISE=100
MARK="store-proof-read-split"
failures=0

step() { printf '    %s\n' "$*"; }

bad() { printf '    FAILED: %s\n' "$*" >&2; failures=$((failures + 1)); }

stop() { printf '    FAILED: %s\n' "$*" >&2; exit 1; }

ask() {
	local out line
	out="$($STORE_PROOF_PHP "/app/local.d/${PROBE_FILE}" "$@" 2>&1)" \
		|| stop "the probe could not answer '$1': $(tail -5 <<< "$out")"
	line="$(grep '^answer ' <<< "$out" | tail -1 || true)"
	[ -n "$line" ] || stop "the probe gave no answer to '$1': $(tail -5 <<< "$out")"
	printf '%s\n' "${line#answer }"
}

field() {
	local token
	for token in $1; do
		case "$token" in
			"$2"=*) printf '%s' "${token#*=}"; return 0 ;;
		esac
	done
	return 1
}

magento() {
	local out
	out="$($STORE_PROOF_MAGENTO "$@" 2>&1)" || stop "bin/magento $* failed: $(tail -5 <<< "$out")"
	printf '%s\n' "$out"
}

sql() {
	local out
	out="$($STORE_PROOF_SQL 2>&1 <<< "$1")" || stop "the database refused: $1 ($(tail -3 <<< "$out"))"
	printf '%s\n' "$out"
}

value() { sql "$1" | tail -1 | tr -d '[:space:]'; }

# How many SELECTs each server counted while one storefront request ran: "primary=N replica=N" and the request's own answer.
# It runs in a command substitution, where a failed probe would otherwise go on with nothing, so each one ends it.
counted() {
	local before after answer
	before="$(ask servers "$REPLICA_HOST")" || exit 1
	answer="$(ask web "$FPM" "$@")" || exit 1
	after="$(ask servers "$REPLICA_HOST")" || exit 1
	printf 'primary=%s replica=%s %s\n' \
		"$(($(field "$after" primary_selects) - $(field "$before" primary_selects)))" \
		"$(($(field "$after" replica_selects) - $(field "$before" replica_selects)))" \
		"$answer"
}

cleanup() {
	if [ -f "$ENV_KEPT" ]; then
		cp -p "$ENV_KEPT" "$ENV_FILE" && rm -f "$ENV_KEPT"
	fi
	# PHP-FPM may still hold the last block this run wrote, and where it does not re-read a changed file it
	# would keep it until restarted. The probe is the only thing that can tell it to let go, so it is asked
	# before it is removed.
	if [ -f "$PROBE" ]; then
		$STORE_PROOF_PHP "/app/local.d/${PROBE_FILE}" web "$FPM" "drop=1" >/dev/null 2>&1 || true
	fi
	rm -f "$PROBE"
	# The breaker's markers this run made, for the host that never answered and for the replica. One that was
	# there before the run is the store's own, and stays.
	local marker
	for marker in "${STORE_PROOF_STORE}/var/${dead_marker:-none}".* "${STORE_PROOF_STORE}/var/${replica_marker:-none}".*; do
		[ -e "$marker" ] || continue
		grep -qxF "$marker" <<< "${markers_before:-}" || rm -f "$marker"
	done
	$STORE_PROOF_SQL >/dev/null 2>&1 <<< "DELETE FROM core_config_data WHERE path = 'kingletas_read_split/store_proof/mark' AND value LIKE '${MARK}%';" || true
}
trap cleanup EXIT

mkdir -p "${STORE_PROOF_STORE}/local.d"
cat > "$PROBE" <<-'PHP'
	<?php
	declare(strict_types=1);

	/**
	 * Answers one store proof question per run about where the store's reads go.
	 *
	 * From the command line it writes the read_split block, asks each server what it has counted, and
	 * runs itself under PHP-FPM. Under PHP-FPM it is the storefront request whose reads are being counted.
	 */

	use Magento\Framework\App\Bootstrap;
	use Magento\Framework\App\DeploymentConfig\Writer\PhpFormatter;
	use Magento\Framework\App\ResourceConnection;
	use Magento\Framework\App\State;

	const MARK_PATH = 'kingletas_read_split/store_proof/mark';
	const ENV_PHP = '/app/app/etc/env.php';

	$answer = static function (array $fields): void {
	    $parts = [];
	    foreach ($fields as $key => $value) {
	        $parts[] = $key . '=' . (is_bool($value) ? (int) $value : (string) $value);
	    }
	    echo 'answer ' . implode(' ', $parts) . PHP_EOL;
	};

	if (PHP_SAPI !== 'cli') {
	    header('Content-Type: text/plain');
	    if (isset($_GET['see'])) {
	        // Which replica host PHP-FPM's own copy of env.php names, as it stands, without asking it to re-read.
	        $seen = include ENV_PHP;
	        $answer(['block' => $seen['db']['read_split']['replica']['host'] ?? 'none']);
	        exit(0);
	    }
	    // env.php may have been rewritten a moment ago, and a cached copy would route by the old block.
	    if (function_exists('opcache_invalidate')) {
	        opcache_invalidate(ENV_PHP, true);
	    }
	    if (isset($_GET['drop'])) {
	        $answer(['dropped' => 1]);
	        exit(0);
	    }
	    require '/app/app/bootstrap.php';
	    $objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
	    $objectManager->get(State::class)->setAreaCode('frontend');
	    $resource = $objectManager->get(ResourceConnection::class);
	    $connection = $resource->getConnection();
	    $table = $resource->getTableName('core_config_data');
	    $website = $resource->getTableName('store_website');
	    $reads = (int) ($_GET['reads'] ?? 0);
	    $mark = (string) ($_GET['mark'] ?? '');
	    $fields = ['adapter' => str_replace('\\', '.', get_class($connection)), 'method' => $_SERVER['REQUEST_METHOD']];

	    if (($_GET['write'] ?? '') === '1') {
	        $connection->insert($table, ['scope' => 'default', 'scope_id' => 0, 'path' => MARK_PATH, 'value' => $mark]);
	        $fields['seen'] = (int) ($connection->fetchOne(
	            'SELECT COUNT(*) FROM ' . $table . ' WHERE path = ? AND value = ?',
	            [MARK_PATH, $mark]
	        ) === '1');
	    }
	    $answered = 0;
	    for ($n = 0; $n < $reads; $n++) {
	        $answered += (int) ((int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $website) > 0);
	    }
	    $fields['answered'] = $answered;
	    if (($_GET['write'] ?? '') === '1') {
	        $connection->delete($table, ['path = ?' => MARK_PATH, 'value = ?' => $mark]);
	    }
	    $answer($fields);
	    exit(0);
	}

	$env = static fn (): array => include ENV_PHP;

	$server = static function (array $config, string $host): PDO {
	    [$name, $port] = array_pad(explode(':', $host, 2), 2, '3306');
	    return new PDO(
	        sprintf('mysql:host=%s;port=%s;dbname=%s', $name, $port, $config['dbname']),
	        $config['username'],
	        $config['password'],
	        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
	    );
	};

	$selects = static fn (PDO $pdo): int
	    => (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];

	switch ($argv[1] ?? '') {
	    case 'block':
	        // The block as the README writes it, pointed at the host named; "none" takes the block out.
	        require '/app/vendor/autoload.php';
	        $config = $env();
	        unset($config['db']['read_split']);
	        if ($argv[2] !== 'none') {
	            $config['db']['read_split'] = ['enabled' => true, 'replica' => ['host' => $argv[2]]];
	        }
	        $written = file_put_contents(ENV_PHP, (new PhpFormatter())->format($config));
	        if (function_exists('opcache_invalidate')) {
	            opcache_invalidate(ENV_PHP, true);
	        }
	        $answer(['written' => $written !== false]);
	        break;

	    case 'servers':
	        $config = $env()['db']['connection']['default'];
	        $primary = $server($config, (string) $config['host']);
	        $fields = [
	            'primary_id' => $primary->query('SELECT @@server_id')->fetchColumn(),
	            'primary_selects' => $selects($primary),
	            'reachable' => 0,
	            'monitor' => 0,
	            'replicating' => 0,
	            'replica_id' => '-',
	            'replica_selects' => 0,
	        ];
	        try {
	            $replica = $server($config, $argv[2]);
	            $fields['reachable'] = 1;
	            $fields['replica_id'] = $replica->query('SELECT @@server_id')->fetchColumn();
	            $fields['replica_selects'] = $selects($replica);
	            try {
	                $status = $replica->query('SHOW REPLICA STATUS')->fetch(PDO::FETCH_ASSOC);
	                $fields['monitor'] = 1;
	                $fields['replicating'] = (int) (is_array($status)
	                    && ($status['Slave_IO_Running'] ?? '') === 'Yes'
	                    && ($status['Slave_SQL_Running'] ?? '') === 'Yes');
	            } catch (PDOException) {
	                $fields['monitor'] = 0;
	            }
	        } catch (PDOException) {
	            $fields['reachable'] = 0;
	        }
	        $answer($fields);
	        break;

	    case 'marker':
	        // The name the module's breaker gives its markers for one replica host, so the proof removes only its own.
	        echo 'answer name=kingletas_read_split-' . substr(hash('sha256', '/app' . "\0" . $argv[2]), 0, 16) . PHP_EOL;
	        break;

	    case 'web':
	        // One GET to this file through PHP-FPM, spoken as FastCGI, so the module sees a storefront request.
	        $query = $argv[3] ?? '';
	        $socket = @stream_socket_client($argv[2], $errno, $error, 5);
	        if ($socket === false) {
	            fwrite(STDERR, 'PHP-FPM did not answer at ' . $argv[2] . ': ' . $error . PHP_EOL);
	            exit(1);
	        }
	        stream_set_timeout($socket, 120);
	        $record = static function (int $type, string $content): string {
	            $pad = (8 - strlen($content) % 8) % 8;
	            return pack('CCnnCC', 1, $type, 1, strlen($content), $pad, 0) . $content . str_repeat("\0", $pad);
	        };
	        $length = static fn (int $n): string => $n < 128 ? chr($n) : pack('N', $n | 0x80000000);
	        $params = '';
	        foreach ([
	            'GATEWAY_INTERFACE' => 'FastCGI/1.0',
	            'REQUEST_METHOD' => 'GET',
	            'SCRIPT_FILENAME' => __FILE__,
	            'SCRIPT_NAME' => '/' . basename(__FILE__),
	            'REQUEST_URI' => '/' . basename(__FILE__) . '?' . $query,
	            'QUERY_STRING' => $query,
	            'SERVER_NAME' => 'localhost',
	            'HTTP_HOST' => 'localhost',
	            'SERVER_PORT' => '80',
	            'SERVER_PROTOCOL' => 'HTTP/1.1',
	            'REMOTE_ADDR' => '127.0.0.1',
	            'CONTENT_LENGTH' => '0',
	        ] as $name => $value) {
	            $params .= $length(strlen($name)) . $length(strlen($value)) . $name . $value;
	        }
	        fwrite($socket, $record(1, pack('nCxxxxx', 1, 0)) . $record(4, $params) . $record(4, '') . $record(5, ''));
	        $out = '';
	        $err = '';
	        while (!feof($socket)) {
	            $header = fread($socket, 8);
	            if ($header === false || strlen($header) < 8) {
	                break;
	            }
	            $head = unpack('Cversion/Ctype/nid/nlen/Cpad/Creserved', $header);
	            $content = $head['len'] > 0 ? (string) stream_get_contents($socket, $head['len']) : '';
	            if ($head['pad'] > 0) {
	                fread($socket, $head['pad']);
	            }
	            if ($head['type'] === 6) {
	                $out .= $content;
	            } elseif ($head['type'] === 7) {
	                $err .= $content;
	            } elseif ($head['type'] === 3) {
	                break;
	            }
	        }
	        echo $out . PHP_EOL;
	        if ($err !== '') {
	            fwrite(STDERR, $err);
	        }
	        break;

	    default:
	        echo 'unknown question' . PHP_EOL;
	        exit(2);
	}
	PHP

# --- the store this proof needs -----------------------------------------------

step "$MODULE_NAME is enabled"
grep -qi 'enabled' <<< "$(magento module:status "$MODULE_NAME")" || stop "$MODULE_NAME is not enabled"

step "the store has a replica its own user can read and ask for its replication status"
servers="$(ask servers "$REPLICA_HOST")"
[ "$(field "$servers" reachable)" = "1" ] \
	|| stop "nothing answers the store's user at ${REPLICA_HOST}. This proof needs a replica. On a store run by Kapelos (https://github.com/kingletas/kapelos), one is added with: kapelos scale replica=1 -y"
[ "$(field "$servers" monitor)" = "1" ] \
	|| stop "the store's user may not ask ${REPLICA_HOST} for its replication status, so the module would keep it out of use. On the replica, as root: GRANT SLAVE MONITOR ON *.* TO the store's user"
[ "$(field "$servers" replicating)" = "1" ] || stop "${REPLICA_HOST} is not replicating: one of its replication threads is stopped"
[ "$(field "$servers" primary_id)" != "$(field "$servers" replica_id)" ] \
	|| stop "${REPLICA_HOST} answers with the primary's own server id, so reads on it could not be told apart"

step "nothing is left at the path this proof writes"
[ "$(value "SELECT COUNT(*) FROM core_config_data WHERE path = 'kingletas_read_split/store_proof/mark';")" = "0" ] \
	|| stop "the store already holds a value at kingletas_read_split/store_proof/mark"

replica_marker="$(field "$(ask marker "${REPLICA_HOST}:3306")" name)"
dead_marker="$(field "$(ask marker "${DEAD_HOST}:3306")" name)"
markers_before="$(ls "${STORE_PROOF_STORE}/var/${replica_marker}".* "${STORE_PROOF_STORE}/var/${dead_marker}".* 2>/dev/null || true)"

block_before="$(field "$(ask web "$FPM" "see=1")" block)"

[ ! -e "$ENV_KEPT" ] || stop "${ENV_KEPT} is left from a run that did not finish. It is the store's env.php from before that run: put it back, then delete it"
cp -p "$ENV_FILE" "$ENV_KEPT"

# --- installed and not configured: nothing changes ------------------------------

step "with no read_split block, the status says not configured and every read stays on the primary"
[ "$(field "$(ask block none)" written)" = "1" ] || stop "app/etc/env.php could not be written"
status="$(magento kingletas:read-split:status)"
grep -q 'Read split: not configured' <<< "$status" || bad "the status without a block was: $(head -1 <<< "$status")"
run="$(counted "reads=${READS}")"
[ "$(field "$run" answered)" = "$READS" ] || bad "only $(field "$run" answered) of ${READS} reads were answered with no block"
[ "$(field "$run" primary)" -ge "$READS" ] || bad "with no block the primary counted $(field "$run" primary) reads, fewer than the ${READS} asked"
[ "$(field "$run" replica)" -lt "$NOISE" ] || bad "with no block the replica counted $(field "$run" replica) reads"

# --- configured as the README shows --------------------------------------------

step "with the README's block, the status says active with a closed breaker"
[ "$(field "$(ask block "$REPLICA_HOST")" written)" = "1" ] || stop "app/etc/env.php could not be written"
status="$(magento kingletas:read-split:status)"
grep -q 'Read split: active' <<< "$status" || bad "the status with the block was: $(head -1 <<< "$status")"
grep -q 'Breaker: closed' <<< "$status" || bad "the breaker was not closed before any request: $(tail -1 <<< "$status")"

step "a storefront request's plain reads go to the replica"
run="$(counted "reads=${READS}")"
grep -q 'ReadSplitMysql' <<< "$(field "$run" adapter)" || bad "the store's connection is $(field "$run" adapter), not the module's adapter"
[ "$(field "$run" answered)" = "$READS" ] || bad "only $(field "$run" answered) of ${READS} reads were answered"
[ "$(field "$run" replica)" -ge "$READS" ] || bad "the replica counted $(field "$run" replica) reads, fewer than the ${READS} asked"
[ "$(field "$run" primary)" -lt "$NOISE" ] || bad "the primary counted $(field "$run" primary) reads that should have gone to the replica"

step "after the request's first write, its reads stay on the primary and see the write"
run="$(counted "reads=${READS}&write=1&mark=${MARK}-$$")"
[ "$(field "$run" seen)" = "1" ] || bad "the request did not read back the row it had just written"
[ "$(field "$run" answered)" = "$READS" ] || bad "only $(field "$run" answered) of ${READS} reads were answered after the write"
[ "$(field "$run" primary)" -ge "$READS" ] || bad "after a write the primary counted $(field "$run" primary) reads, fewer than the ${READS} asked"
[ "$(field "$run" replica)" -lt "$NOISE" ] || bad "after a write the replica still counted $(field "$run" replica) reads"

step "the breaker is still closed after both requests"
grep -q 'Breaker: closed' <<< "$(magento kingletas:read-split:status)" || bad "the breaker opened during a healthy run"

# --- a replica that does not answer --------------------------------------------

step "with a replica that does not answer, every read is answered by the primary and the breaker opens"
[ "$(field "$(ask block "$DEAD_HOST")" written)" = "1" ] || stop "app/etc/env.php could not be written"
run="$(counted "reads=${READS}")"
[ "$(field "$run" answered)" = "$READS" ] || bad "only $(field "$run" answered) of ${READS} reads were answered with the replica gone"
[ "$(field "$run" primary)" -ge "$READS" ] || bad "with the replica gone the primary counted $(field "$run" primary) reads, fewer than the ${READS} asked"
status="$(magento kingletas:read-split:status)"
grep -q 'Breaker: open' <<< "$status" || bad "the breaker did not open for a replica that does not answer: $(tail -1 <<< "$status")"

step "app/etc/env.php is put back as it was, and PHP-FPM lets go of the copy it held"
cp -p "$ENV_KEPT" "$ENV_FILE" && rm -f "$ENV_KEPT"
held="$(field "$(ask web "$FPM" "see=1")" block)"
[ "$(field "$(ask web "$FPM" "drop=1")" dropped)" = "1" ] || bad "PHP-FPM could not be asked to drop its copy of env.php"
block_after="$(field "$(ask web "$FPM" "see=1")" block)"
step "PHP-FPM named the replica '${block_before}' before the proof, held '${held}' once the file was back, and names '${block_after}' now"
[ "$block_after" = "$block_before" ] \
	|| bad "PHP-FPM still routes by '${block_after}', not the '${block_before}' the store had before the proof. Restart PHP-FPM"

[ "$failures" -eq 0 ] || { printf '    %s assertion(s) failed\n' "$failures" >&2; exit 1; }
step "every assertion held"
