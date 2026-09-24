<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Model\Routing;

use Kingletas\ReadSplit\Model\Gtid\PendingPosition;
use Kingletas\ReadSplit\Model\Gtid\PositionCookie;
use Kingletas\ReadSplit\Model\Gtid\PositionState;
use Kingletas\ReadSplit\Model\Request\RequestScopeInterface;
use Kingletas\ReadSplit\Model\Request\WriteLedger;
use Kingletas\ReadSplit\Model\Settings;
use Kingletas\ReadSplit\Model\Statement\Classification;
use Kingletas\ReadSplit\Model\Statement\Classifier;
use Kingletas\ReadSplit\Model\Statement\StatementKind;

/**
 * Decides, one statement at a time, whether the replica may answer, and remembers what in this request rules it out.
 */
class Router
{
    private bool $pinned = false;

    private bool $wrote = false;

    private ?bool $commandLine = null;

    private ?bool $storefrontRead = null;

    private ?PendingPosition $pendingPosition = null;

    private readonly string $primaryOnlyPattern;

    public function __construct(
        private readonly Settings $settings,
        private readonly Classifier $classifier,
        private readonly RequestScopeInterface $requestScope,
        private readonly PositionCookie $positionCookie,
        private readonly WriteLedger $writeLedger,
        private readonly Replica $replica
    ) {
        $this->primaryOnlyPattern = $this->tablePattern($settings->primaryOnlyTables());
    }

    public function route(string $sql, int $transactionLevel): Route
    {
        if ($this->isCommandLine() || ($this->pinned && $this->wrote)) {
            return Route::Primary;
        }

        $statement = $this->classifier->classify($sql);

        return match ($statement->kind()) {
            StatementKind::Read => $this->routeRead($statement, $transactionLevel),
            StatementKind::SessionState => Route::PrimaryAndReplica,
            StatementKind::PinningRead => $this->pin(),
            StatementKind::Write => $this->write(),
        };
    }

    /**
     * Notes a statement that reaches the primary without being routed, so a write there still pins the request.
     */
    public function notePrimaryStatement(string $sql): void
    {
        if ($this->isCommandLine() || ($this->pinned && $this->wrote)) {
            return;
        }

        match ($this->classifier->classify($sql)->kind()) {
            StatementKind::Read => null,
            StatementKind::Write => $this->write(),
            // Session state set outside routing is never replayed, so the replica could not match it.
            StatementKind::SessionState, StatementKind::PinningRead => $this->pin(),
        };
    }

    /**
     * Sends the rest of the request to the primary.
     */
    public function pin(): Route
    {
        $this->pinned = true;

        return Route::Primary;
    }

    /**
     * The replica's answer, or null when the caller has to ask the primary.
     */
    public function askReplica(string $sql, mixed $bind): mixed
    {
        return $this->replica->query($sql, $bind, $this->position()->position());
    }

    public function rememberSessionState(string $sql, mixed $bind): void
    {
        $this->replica->remember($sql, $bind);
    }

    public function closeReplica(): void
    {
        $this->replica->close();
    }

    public function reset(): void
    {
        $this->pinned = false;
        $this->wrote = false;
        $this->storefrontRead = null;
        $this->pendingPosition = null;
        $this->replica->reset();
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    private function routeRead(Classification $statement, int $transactionLevel): Route
    {
        if ($this->pinned || $transactionLevel > 0 || $statement->mentions($this->primaryOnlyPattern)) {
            return Route::Primary;
        }

        return $this->isStorefrontRead() && $this->replica->isAvailable() && $this->positionAllowsReplica()
            ? Route::Replica
            : Route::Primary;
    }

    /**
     * Once a request is pinned and has written, nothing later can change where it reads, so nothing is classified.
     */
    private function write(): Route
    {
        $this->wrote = true;
        $this->writeLedger->recordWrite();

        return $this->pin();
    }

    /**
     * A pooled replica cannot prove it caught up, so a position holds the visitor on the primary until it expires.
     */
    private function positionAllowsReplica(): bool
    {
        return match ($this->position()->state()) {
            PositionState::None => true,
            PositionState::Pending => !$this->settings->isPooled(),
            PositionState::Hold, PositionState::Rejected => false,
        };
    }

    private function position(): PendingPosition
    {
        return $this->pendingPosition ??= $this->positionCookie->read($this->settings->positionLifetime());
    }

    /**
     * Cached once the area is known, and asked again until then.
     */
    private function isStorefrontRead(): bool
    {
        $this->storefrontRead ??= $this->requestScope->isStorefrontRead();

        return $this->storefrontRead === true;
    }

    private function isCommandLine(): bool
    {
        return $this->commandLine ??= $this->requestScope->isCommandLine();
    }

    /**
     * @param string[] $tables
     */
    private function tablePattern(array $tables): string
    {
        if ($tables === []) {
            return '';
        }

        $names = implode('|', array_map(static fn (string $table): string => preg_quote($table, '/'), $tables));

        return '/(?<![\w$])`?(?:' . $names . ')`?(?![\w$])/i';
    }
}
