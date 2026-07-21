<?php

namespace App\Controllers;

use App\Models\ProjectFileModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Mimes;
use Psr\Log\LoggerInterface;

/**
 * Project file manager (`project_files`) — upload/list/download/delete for
 * files attached to a `projects` row. Bytes live under
 * `writable/uploads/projects/{project_id}/{stored_name}`; gated by
 * `authFilter` at the route plus per-project `project_role()`/
 * `can_edit_project()` (`access_helper.php`) — the IDOR guard, since a path
 * id alone never proves access. See
 * `kanban/processing/20260721-04-projects-and-files.md`.
 *
 * **Security-critical.** `download()`/`delete()` build the on-disk path
 * exclusively from the stored `project_files` row (`project_id`/
 * `stored_name`), never from request input, so there is no path-traversal
 * surface. `upload()` allowlists extensions AND real (fileinfo-detected)
 * mime types via CI4's `ext_in`/`mime_in` rules — a renamed `.php` is
 * rejected because its detected mime type won't match the allowlist.
 */
class ProjectFiles extends BaseController
{
    /**
     * Extensions accepted by `upload()`. The matching `mime_in` allowlist is
     * derived from `Config\Mimes::$mimes` at validation time so the two
     * lists can never drift apart.
     */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip',
    ];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper(['auth', 'access']);
    }

    /**
     * JSON list of a project's files, newest first.
     */
    public function index(int $projectId): ResponseInterface
    {
        if (project_role($projectId) === null) {
            return $this->jsonFailed('You do not have access to this project.', 403);
        }

        return $this->jsonSuccess('OK', ['files' => model(ProjectFileModel::class)->forProject($projectId)]);
    }

    public function upload(int $projectId): ResponseInterface
    {
        if (! can_edit_project($projectId)) {
            return $this->jsonFailed('You do not have permission to upload files to this project.', 403);
        }

        if (! $this->validate($this->uploadValidationRules())) {
            return $this->jsonFailed($this->validator->getErrors());
        }

        $file = $this->request->getFile('file');

        $dir = WRITEPATH . 'uploads/projects/' . $projectId . '/';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return $this->jsonFailed('Could not create the upload directory.', 500);
        }

        $storedName = $file->getRandomName();
        $file->move($dir, $storedName);

        $fileModel = model(ProjectFileModel::class);
        $id        = $fileModel->insert([
            'project_id'    => $projectId,
            'uploaded_by'   => current_user_id(),
            'original_name' => $file->getClientName(),
            'stored_name'   => $storedName,
            'mime'          => $file->getMimeType() ?: $file->getClientMimeType(),
            'size'          => $file->getSize(),
        ]);

        return $this->jsonSuccess('File uploaded.', ['file' => $fileModel->findWithUploader((int) $id)]);
    }

    /**
     * Streams a project file. Path is built exclusively from the stored
     * row — never from request input.
     */
    public function download(int $fileId): ResponseInterface
    {
        $file = model(ProjectFileModel::class)->findFile($fileId);

        if (! $file) {
            return $this->response->setStatusCode(404)->setBody('File not found.');
        }

        if (project_role((int) $file->project_id) === null) {
            return $this->response->setStatusCode(403)->setBody('Forbidden.');
        }

        $absPath = WRITEPATH . 'uploads/projects/' . $file->project_id . '/' . $file->stored_name;

        if (! is_file($absPath)) {
            return $this->response->setStatusCode(404)->setBody('File not found.');
        }

        return $this->response->download($absPath, null)->setFileName($file->original_name);
    }

    public function delete(int $fileId): ResponseInterface
    {
        $file = model(ProjectFileModel::class)->findFile($fileId);

        if (! $file) {
            return $this->jsonFailed('File not found.', 404);
        }

        if (! can_edit_project((int) $file->project_id)) {
            return $this->jsonFailed('You do not have permission to manage this project\'s files.', 403);
        }

        model(ProjectFileModel::class)->delete($fileId);
        @unlink(WRITEPATH . 'uploads/projects/' . $file->project_id . '/' . $file->stored_name);

        return $this->jsonSuccess('File deleted.');
    }

    /**
     * `mime_in` allowlist is derived from `Config\Mimes::$mimes` for
     * `ALLOWED_EXTENSIONS` rather than hand-maintained, so it can't drift
     * from the extension allowlist.
     *
     * @return array<string, string>
     */
    private function uploadValidationRules(): array
    {
        $mimes = [];

        foreach (self::ALLOWED_EXTENSIONS as $extension) {
            foreach ((array) (Mimes::$mimes[$extension] ?? []) as $mime) {
                $mimes[] = $mime;
            }
        }

        return [
            'file' => sprintf(
                'uploaded[file]|max_size[file,10240]|ext_in[file,%s]|mime_in[file,%s]',
                implode(',', self::ALLOWED_EXTENSIONS),
                implode(',', array_unique($mimes)),
            ),
        ];
    }
}
