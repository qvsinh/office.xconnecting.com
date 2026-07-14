<?php

use App\Models\EmailModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class EmailModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'id'         => bin2hex(random_bytes(8)),
            'subject'    => 'Verify your email',
            'content'    => '<p>Hello</p>',
            'from_email' => 'noreply@example.com',
            'to_email'   => 'user@example.com',
            'status'     => 1,
            'name'       => 'signup_verify_email',
        ], $overrides);
    }

    public function testFindEmailReturnsRowByVarcharId(): void
    {
        $model = new EmailModel();
        $row   = $this->baseRow();
        $model->insert($row);

        $email = $model->findEmail($row['id']);

        $this->assertNotNull($email);
        $this->assertSame($row['subject'], $email->subject);
    }

    public function testFindEmailReturnsNullForUnknownId(): void
    {
        $model = new EmailModel();

        $this->assertNull($model->findEmail('does-not-exist'));
    }

    public function testSearchAdminKeywordMatchesExactEmailWhenTermIsValidEmail(): void
    {
        $model = new EmailModel();
        $model->insert($this->baseRow(['id' => 'a', 'to_email' => 'alice@example.com', 'name' => 'welcome']));
        $model->insert($this->baseRow(['id' => 'b', 'to_email' => 'alice2@example.com', 'name' => 'welcome']));

        $rows = $model->searchAdmin(['keyword' => 'alice@example.com']);

        $this->assertCount(1, $rows);
        $this->assertSame('alice@example.com', $rows[0]->to_email);
    }

    public function testSearchAdminKeywordFallsBackToLikeAcrossNameSubjectToEmail(): void
    {
        $model = new EmailModel();
        $model->insert($this->baseRow(['id' => 'a', 'name' => 'signup_verify_email', 'subject' => 'Verify']));
        $model->insert($this->baseRow(['id' => 'b', 'name' => 'change_email', 'subject' => 'Changed']));

        $rows = $model->searchAdmin(['keyword' => 'signup']);

        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]->id);
    }

    public function testSearchAdminFiltersByStatus(): void
    {
        $model = new EmailModel();
        $model->insert($this->baseRow(['id' => 'sent', 'status' => 1]));
        $model->insert($this->baseRow(['id' => 'failed', 'status' => 2]));

        $rows = $model->searchAdmin(['status' => 2]);

        $this->assertCount(1, $rows);
        $this->assertSame('failed', $rows[0]->id);
    }

    public function testSearchAdminDefaultsToCreatedAtDescending(): void
    {
        $model = new EmailModel();
        $model->insert($this->baseRow(['id' => 'first']));
        sleep(1);
        $model->insert($this->baseRow(['id' => 'second']));

        $rows = $model->searchAdmin([]);

        $this->assertSame('second', $rows[0]->id);
        $this->assertSame('first', $rows[1]->id);
    }

    public function testSearchAdminRejectsArbitrarySortColumnAndFallsBackToCreatedAt(): void
    {
        $model = new EmailModel();
        $model->insert($this->baseRow(['id' => 'a']));

        $rows = $model->searchAdmin([], 'subject; DROP TABLE emails;', 'asc');

        // Falls back to default sort (created_at) rather than erroring or
        // injecting the malicious column into the query.
        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]->id);
    }

    public function testCountAdminMatchesUnpaginatedRowCountForSameFilters(): void
    {
        $model = new EmailModel();
        for ($i = 0; $i < 5; $i++) {
            $model->insert($this->baseRow(['id' => "row-{$i}", 'status' => 1]));
        }

        $filters = ['status' => 1];
        $page    = $model->searchAdmin($filters, 'created_at', 'asc', 2, 0);

        $this->assertCount(2, $page);
        $this->assertSame(5, $model->countAdmin($filters));
    }

    public function testSoftDeleteExcludesRowFromFindAndSearch(): void
    {
        $model = new EmailModel();
        $row   = $this->baseRow();
        $model->insert($row);

        $model->delete($row['id']);

        $this->assertNull($model->findEmail($row['id']));
        $this->assertCount(0, $model->searchAdmin([]));
        $this->assertSame(0, $model->countAdmin([]));

        // Row still physically present (soft delete), just filtered out.
        $this->assertSame(1, $model->builder()->where('id', $row['id'])->countAllResults());
    }
}
