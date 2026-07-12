---
name: codeigniter4
description: Use when working on this CodeIgniter 4 / PHP 8.3 project (xpages.vn). Provides CI4-correct patterns for Controllers, Models, Routes, Filters, Views, Services, Spark CLI, Validation, Sessions, Database, and Testing — explicitly avoiding Laravel idioms (Eloquent, Artisan, Blade, Facades) which do NOT apply.
---

# CodeIgniter 4 — Project Skill

This project uses **CodeIgniter 4 + PHP 8.3 + MySQL/MariaDB**. CI4 is NOT Laravel. Do not import Laravel patterns; if uncertain, consult the official CI4 user guide (https://codeigniter.com/user_guide/) instead of guessing.

## When to invoke

Whenever you write, edit, or review PHP code in `app/` — Controllers, Models, Libraries, Filters, Views, Config, Commands, Database migrations/seeds, or tests under `tests/`.

## Hard Rules

1. **No raw SQL with user input.** Use Query Builder (`$this->db->table()`) or Model methods with bound parameters.
2. **No Laravel.** No Eloquent, no Artisan, no Blade, no Facades, no service container auto-injection, no `App\Http\...` namespaces.
3. **All output in views must be escaped** via `esc()` or `\Laminas\Escaper\Escaper`. Never `echo $userInput` directly.
4. **Routes are explicit** in `app/Config/Routes.php`. CI4 has no route auto-discovery convention by default.
5. **Controllers extend `BaseController`** (public) or `AdminController` / `AuthenticatedController` (protected admin area).
6. **Data access via Models** in `app/Models/` — not raw queries scattered in controllers.
7. **Secrets in `.env`**, read via `getenv()` or CI4 config — never hardcode.
8. **Run impact analysis** with `codegraph_impact` before editing any non-trivial symbol (see CLAUDE.md CodeGraph section).

## Core CI4 Patterns

### Controllers

```php
<?php
namespace App\Controllers;

class Posts extends BaseController
{
    public function index()
    {
        $model = model('PostModel');           // CI4 helper, NOT new PostModel()
        $posts = $model->where('status', 'published')->paginate(20);
        return view('default/posts/index', ['posts' => $posts, 'pager' => $model->pager]);
    }

    public function show(string $slug)
    {
        $post = model('PostModel')->where('slug', $slug)->first();
        if (! $post) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        return view('default/posts/show', ['post' => $post]);
    }
}
```

### Models

```php
<?php
namespace App\Models;

use CodeIgniter\Model;

class PostModel extends Model
{
    protected $table         = 'posts';
    protected $primaryKey    = 'id';
    protected $useAutoIncrement = true;
    protected $returnType    = 'array';        // or an entity class
    protected $useSoftDeletes = true;
    protected $allowedFields = ['title', 'slug', 'content', 'status', 'author_id'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $validationRules = [
        'title' => 'required|max_length[255]',
        'slug'  => 'required|alpha_dash|is_unique[posts.slug,id,{id}]',
    ];
}
```

### Routes — `app/Config/Routes.php`

```php
$routes->get('/', 'Home::index');
$routes->get('posts/(:segment)', 'Posts::show/$1');

$routes->group('admin', ['filter' => 'adminFilter'], static function ($routes) {
    $routes->get('posts', 'Admin\PostController::index');
    $routes->post('posts/(:num)/update', 'Admin\PostController::update/$1');
});
```

### Filters — `app/Filters/`

```php
<?php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! session()->get('user_id')) {
            return redirect()->to('/login');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
```
Register in `app/Config/Filters.php`.

### Views — `app/Views/`

```php
<!-- default/posts/show.php -->
<?= $this->extend('default/layout') ?>
<?= $this->section('content') ?>
  <h1><?= esc($post['title']) ?></h1>
  <div><?= $post['content']  /* already-sanitized HTML from editor */ ?></div>
<?= $this->endSection() ?>
```
Use `esc()` for any user/dynamic text. Use `<?= $this->include('partials/foo') ?>` for partials.

### Validation

```php
$rules = [
    'email'    => 'required|valid_email',
    'password' => 'required|min_length[8]',
];
if (! $this->validate($rules)) {
    return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
}
```

### Database / Query Builder

```php
$db = \Config\Database::connect();
$rows = $db->table('posts')
    ->select('id, title, slug')
    ->where('status', 'published')
    ->whereIn('category_id', $ids)
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->get()
    ->getResultArray();
```
Always bind parameters. NEVER concatenate `$_GET`/`$_POST` into a query string.

### Spark CLI — `app/Commands/`

```php
<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CleanSessions extends BaseCommand
{
    protected $group       = 'maintenance';
    protected $name        = 'sessions:clean';
    protected $description = 'Remove expired session rows.';

    public function run(array $params)
    {
        $deleted = model('SessionModel')->cleanExpired();
        CLI::write("Deleted {$deleted} expired sessions", 'green');
    }
}
```
Run via `php spark sessions:clean`.

### Migrations — `app/Database/Migrations/`

```php
public function up()
{
    $this->forge->addField([
        'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
        'title'      => ['type' => 'VARCHAR', 'constraint' => 255],
        'created_at' => ['type' => 'DATETIME', 'null' => true],
    ]);
    $this->forge->addPrimaryKey('id');
    $this->forge->createTable('posts');
}
```

### Sessions, Flash, Redirect

```php
session()->set('user_id', $user['id']);
session()->setFlashdata('success', 'Saved.');
return redirect()->to('/admin/posts')->with('success', 'Created');
```

### Services (CI4's DI)

Use `service('name')` — e.g. `service('request')`, `service('session')`, `service('encrypter')`. Register custom services in `app/Config/Services.php`.

## Testing (PHPUnit + CIUnitTestCase)

```php
<?php
namespace Tests\App\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class PostModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $seed    = 'PostSeeder';

    public function testFindBySlugReturnsPost(): void
    {
        $post = model('PostModel')->where('slug', 'hello')->first();
        $this->assertSame('Hello', $post['title']);
    }
}
```
Run: `vendor/bin/phpunit`.

## Anti-patterns to reject (these are Laravel, not CI4)

| ❌ Don't write              | ✅ Write instead                        |
|----------------------------|-----------------------------------------|
| `Route::get(...)`          | `$routes->get(...)` in `Routes.php`     |
| `Post::find($id)`          | `model('PostModel')->find($id)`         |
| `new PostModel()`          | `model('PostModel')`                    |
| `Auth::user()`             | `session()->get('user_id')` + lookup    |
| `php artisan migrate`      | `php spark migrate`                     |
| `{{ $var }}` (Blade)       | `<?= esc($var) ?>`                      |
| `@extends('layout')`       | `<?= $this->extend('layout') ?>`        |
| `request()->input('x')`    | `$this->request->getPost('x')`          |
| `DB::table('x')->...`      | `$db->table('x')->...`                  |
| `config('app.name')`       | `config('App')->name` or `env('APP_NAME')` |
| Service container binding  | `app/Config/Services.php` factory       |

## Common Commands

```bash
php spark serve              # dev server
php spark routes             # list routes
php spark migrate            # run migrations
php spark make:controller X  # scaffold controller
php spark make:model X       # scaffold model
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix
vendor/bin/phpstan analyse
```

## Authoritative reference

When in doubt, consult: https://codeigniter.com/user_guide/ — NOT Laravel docs, NOT generic PHP framework intuition.
