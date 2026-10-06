<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Unit\Model\Statement;

use Kingletas\ReadSplit\Model\Statement\Classifier;
use Kingletas\ReadSplit\Model\Statement\SessionStateSet;
use Kingletas\ReadSplit\Model\Statement\SqlText;
use Kingletas\ReadSplit\Model\Statement\StatementKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text-level cases the routing tests do not reach: where a keyword may hide, and where it only seems to.
 */
class ClassifierTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: StatementKind}>
     */
    public static function statements(): array
    {
        return [
            'a hash comment hiding nothing' => ["SELECT 1 FROM store # FOR UPDATE\n", StatementKind::Read],
            'a dash comment hiding nothing' => ["SELECT 1 FROM store -- FOR UPDATE\n", StatementKind::Read],
            'a hash inside a backtick name' => ['SELECT `a#b` FROM store FOR UPDATE', StatementKind::PinningRead],
            'an escaped quote inside a value' => ["SELECT 'it\\'s' FROM store FOR UPDATE", StatementKind::PinningRead],
            'a doubled quote inside a value' => ["SELECT 'it''s' FROM store FOR UPDATE", StatementKind::PinningRead],
            'a MariaDB executable comment' => ['SELECT * FROM quote /*M! FOR UPDATE */', StatementKind::PinningRead],
            'a leading comment before a write' => [
                '/* invented tag */ UPDATE quote SET is_active = 0 WHERE entity_id = 3',
                StatementKind::Write,
            ],
            'a semicolon inside a value' => ["SELECT ';' FROM store", StatementKind::Read],
            'nothing at all' => ['', StatementKind::Write],
            'a lock function in lower case' => ["select get_lock('invented', 1)", StatementKind::PinningRead],
            'a column named like a lock function' => ['SELECT invented_get_lock_count FROM store', StatementKind::Read],
            'a lock function with its name quoted' => ["SELECT `get_lock` ('invented', 1)", StatementKind::PinningRead],
            'a sequence function with its name quoted' => ['SELECT `NEXTVAL`(invented_sequence)', StatementKind::Write],
            'a column quoted beside a bracket' => ['SELECT `invented_get_lock` FROM (SELECT 1) t', StatementKind::Read],
            'a user variable set from a function' => ['SET @invented = CONNECTION_ID()', StatementKind::Write],
            'a user variable set from arithmetic' => ['SET @invented = 2 + 3', StatementKind::SessionState],
            'SET with nothing after it' => ['SET', StatementKind::Write],
            'SET NAMES in lower case' => ['set names utf8mb4', StatementKind::SessionState],
            'a SET value with a comma in parentheses' => [
                "SET sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES')",
                StatementKind::SessionState,
            ],
            'a collation set by its local name' => [
                'SET LOCAL collation_connection = utf8mb4_bin',
                StatementKind::SessionState,
            ],
            'an allowed and a refused variable together' => ["SET sql_mode = '', autocommit = 0", StatementKind::Write],
            'a server variable read in a SET value' => ['SET @invented = @@version', StatementKind::SessionState],
        ];
    }

    #[DataProvider('statements')]
    public function testEachStatementIsSortedByWhatItDoes(string $sql, StatementKind $kind): void
    {
        $this->assertSame($kind, $this->classifier()->classify($sql)->kind());
    }

    public function testATableNamedInAValueOrACommentIsNotMentioned(): void
    {
        $pattern = '/(?<![\w$])`?(?:session)`?(?![\w$])/i';

        $this->assertTrue($this->classifier()->classify('SELECT * FROM `session` WHERE 1')->mentions($pattern));
        $this->assertTrue($this->classifier()->classify('SELECT * FROM db.session')->mentions($pattern));
        $this->assertFalse($this->classifier()->classify("SELECT 'session' FROM store")->mentions($pattern));
        $this->assertFalse($this->classifier()->classify('SELECT 1 FROM store /* session */')->mentions($pattern));
        $this->assertFalse($this->classifier()->classify('SELECT * FROM customer_session')->mentions($pattern));
        $this->assertFalse($this->classifier()->classify('SELECT * FROM session')->mentions(''));
    }

    public function testTheVerbIsFoundPastOpeningParentheses(): void
    {
        $sqlText = new SqlText();

        $this->assertSame('SELECT', $sqlText->verb($sqlText->executable("  (\n(SELECT 1) UNION (SELECT 2))")));
        $this->assertSame('', $sqlText->verb($sqlText->executable('123')));
    }

    private function classifier(): Classifier
    {
        return new Classifier(new SqlText(), new SessionStateSet());
    }
}
