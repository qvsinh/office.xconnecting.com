<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * System logs viewer over `writable/logs/`. Gated by
 * `authFilter` + `adminFilter` (see Config/Routes.php).
 *
 * **Path-traversal-critical.** Every filename accepted from the request
 * (`preview`/`download`/`delete`) is reduced to `basename()` and then
 * checked for membership in `log_dir_files()` — the real set of `.log`
 * files currently in `writable/logs/` — before any filesystem call. No path
 * is ever built by concatenating raw request input; anything not in that
 * allowlist is a 404, nothing is read/downloaded/deleted.
 */
class SystemLogs extends BaseController
{
    public function index(): string
    {
        helper('system');

        $page  = max(1, (int) $this->request->getGet('page'));
        $limit = $this->resolveLimit();
        $files = log_dir_files();

        return view('admin/system/logs', [
            'items' => array_slice($files, ($page - 1) * $limit, $limit),
            'total' => count($files),
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    public function preview(): ResponseInterface
    {
        helper('system');

        $name = $this->resolveAllowedFile((string) $this->request->getGet('file'));

        if ($name === null) {
            return $this->jsonFailed('File not found.', 404);
        }

        return $this->jsonSuccess('OK', [
            'name'    => $name,
            'content' => tail_lines(WRITEPATH . 'logs/' . $name),
        ]);
    }

    public function download(): ResponseInterface
    {
        helper('system');

        $name = $this->resolveAllowedFile((string) $this->request->getGet('file'));

        if ($name === null) {
            return $this->response->setStatusCode(404)->setBody('File not found.');
        }

        return $this->response->download(WRITEPATH . 'logs/' . $name, null)->setFileName($name);
    }

    public function delete(): ResponseInterface
    {
        helper('system');

        $name = $this->resolveAllowedFile((string) $this->request->getPost('file'));

        if ($name === null) {
            return $this->jsonFailed('File not found.', 404);
        }

        if (! @unlink(WRITEPATH . 'logs/' . $name)) {
            return $this->jsonFailed('Could not delete log file.', 500);
        }

        return $this->jsonSuccess('Log file has been deleted.');
    }

    public function deleteAll(): ResponseInterface
    {
        helper('system');

        $deleted = 0;

        foreach (log_dir_files() as $file) {
            if (@unlink(WRITEPATH . 'logs/' . $file['name'])) {
                $deleted++;
            }
        }

        return $this->jsonSuccess("{$deleted} log file(s) deleted.", ['deleted' => $deleted]);
    }

    /**
     * Resolve a request-supplied filename against the real, current set of
     * log files — never trust it enough to touch the filesystem otherwise.
     * `basename()` strips any directory traversal component first; the
     * membership check then rejects anything that isn't an actual `.log`
     * file already sitting in `writable/logs/` (wrong extension, absolute
     * path, `../` escape, or a name that simply doesn't exist there).
     */
    private function resolveAllowedFile(string $requested): ?string
    {
        $name = basename($requested);

        foreach (log_dir_files() as $file) {
            if ($file['name'] === $name) {
                return $name;
            }
        }

        return null;
    }
}
