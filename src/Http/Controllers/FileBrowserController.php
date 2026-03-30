<?php

namespace FileBrowser\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileBrowserController extends Controller
{
    // =========================================================================
    // FRONTEND
    // =========================================================================

    public function index(Request $request)
    {
        $rootPath = $this->getRootPath($request);

        return view('filebrowser::browser', [
            'name' => config('filebrowser.name', 'File Browser'),
            'baseURL' => rtrim(config('filebrowser.prefix', '/file-browser'), '/'),
            'staticURL' => asset('vendor/filebrowser'),
            'rootPath' => $rootPath,
        ]);
    }

    // =========================================================================
    // AUTH (for Vue frontend — returns JWT-like token from Laravel session)
    // =========================================================================

    public function login(Request $request): JsonResponse
    {
        // Already authenticated via Laravel middleware
        $user = auth()->user();
        $token = base64_encode(json_encode([
            'id' => $user->id,
            'name' => $user->name,
            'exp' => time() + 7200,
        ]));

        return response()->json($token);
    }

    public function renew(Request $request): JsonResponse
    {
        return $this->login($request);
    }

    // =========================================================================
    // RESOURCE GET — list directory or get file info
    // =========================================================================

    public function resourceGet(Request $request, string $path = '/'): JsonResponse
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return response()->json('not found', 404);
        }

        $info = $this->fileInfo($resolved, $root);

        if (is_dir($resolved)) {
            $items = [];
            foreach (scandir($resolved) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $items[] = $this->fileInfo($resolved . '/' . $name, $root);
            }

            // Sort
            $sortBy = $request->query('sort', 'name');
            $orderAsc = $request->query('order', 'asc') === 'asc';
            usort($items, function ($a, $b) use ($sortBy, $orderAsc) {
                if ($a['isDir'] !== $b['isDir']) return $a['isDir'] ? -1 : 1;
                $cmp = match ($sortBy) {
                    'size' => $a['size'] <=> $b['size'],
                    'modified' => strtotime($a['modTime']) <=> strtotime($b['modTime']),
                    default => strnatcasecmp($a['name'], $b['name']),
                };
                return $orderAsc ? $cmp : -$cmp;
            });

            $info['items'] = $items;
            $info['numDirs'] = count(array_filter($items, fn($i) => $i['isDir']));
            $info['numFiles'] = count(array_filter($items, fn($i) => !$i['isDir']));
            $info['sorting'] = ['by' => $sortBy, 'asc' => $orderAsc];

            return response()->json($info);
        }

        // File
        if ($info['type'] === 'text' && $info['size'] < 10 * 1024 * 1024) {
            $info['content'] = file_get_contents($resolved);
        }

        if ($checksum = $request->query('checksum')) {
            $info['checksums'] = [];
            if (in_array($checksum, ['md5', 'sha1', 'sha256', 'sha512'])) {
                $info['checksums'][$checksum] = hash_file($checksum, $resolved);
            }
        }

        return response()->json($info);
    }

    // =========================================================================
    // RESOURCE POST — upload file or create directory
    // =========================================================================

    public function resourcePost(Request $request, string $path = '/'): JsonResponse|Response
    {
        $root = $this->getRootPath($request);
        $fullPath = $this->buildPath($root, $path);
        if (!$fullPath) {
            return response()->json('forbidden', 403);
        }

        // Create directory
        if (str_ends_with($path, '/')) {
            @mkdir($fullPath, 0755, true);
            return response()->json(null, 200);
        }

        $override = $request->boolean('override');

        if (file_exists($fullPath) && !$override) {
            return response()->json('conflict', 409);
        }

        // Block dangerous extensions
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $blocked = config('filebrowser.blocked_extensions', ['phtml', 'phar']);
        if (in_array($ext, $blocked)) {
            return response()->json('forbidden file type', 403);
        }

        // Handle file upload
        if ($request->hasFile('files')) {
            $dir = dirname($fullPath);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            foreach ($request->file('files') as $file) {
                $name = preg_replace('/[\/\\\0]/', '', $file->getClientOriginalName());
                $file->move($dir, $name);
            }
            return response()->json(null, 200);
        }

        // Raw body upload
        $dir = dirname($fullPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($fullPath, $request->getContent());

        $stat = stat($fullPath);
        $etag = sprintf('%x-%x', $stat['mtime'], $stat['size']);

        return response()->json(null, 201)->header('ETag', $etag);
    }

    // =========================================================================
    // RESOURCE PUT — save/update file content
    // =========================================================================

    public function resourcePut(Request $request, string $path = '/'): JsonResponse|Response
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved || is_dir($resolved)) {
            return response()->json('not found', 404);
        }

        file_put_contents($resolved, $request->getContent());

        $stat = stat($resolved);
        $etag = sprintf('%x-%x', $stat['mtime'], $stat['size']);

        return response()->json(null, 200)->header('ETag', $etag);
    }

    // =========================================================================
    // RESOURCE DELETE
    // =========================================================================

    public function resourceDelete(Request $request, string $path = '/'): Response
    {
        if ($path === '/' || $path === '') {
            return response('cannot delete root', 403);
        }

        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return response('not found', 404);
        }

        if (is_dir($resolved)) {
            $this->deleteDir($resolved);
        } else {
            @unlink($resolved);
        }

        return response('', 204);
    }

    // =========================================================================
    // RESOURCE PATCH — copy or rename/move
    // =========================================================================

    public function resourcePatch(Request $request, string $path = '/'): Response
    {
        $root = $this->getRootPath($request);
        $action = $request->query('action');
        $destination = urldecode($request->query('destination', ''));
        $override = $request->boolean('override');
        $rename = $request->boolean('rename');

        $srcResolved = $this->resolve($root, $path);
        if (!$srcResolved) {
            return response('source not found', 404);
        }

        $dstResolved = $this->buildPath($root, $destination);
        if (!$dstResolved) {
            return response('invalid destination', 403);
        }

        if (file_exists($dstResolved) && !$override && !$rename) {
            return response('conflict', 409);
        }

        if ($rename && file_exists($dstResolved)) {
            $dir = dirname($dstResolved);
            $base = pathinfo($dstResolved, PATHINFO_FILENAME);
            $ext = pathinfo($dstResolved, PATHINFO_EXTENSION);
            $i = 1;
            do {
                $dstResolved = $dir . '/' . $base . ' (' . $i . ')' . ($ext ? '.' . $ext : '');
                $i++;
            } while (file_exists($dstResolved));
        }

        $dstDir = dirname($dstResolved);
        if (!is_dir($dstDir)) @mkdir($dstDir, 0755, true);

        if ($action === 'copy') {
            if (is_dir($srcResolved)) {
                $this->copyDir($srcResolved, $dstResolved);
            } else {
                @copy($srcResolved, $dstResolved);
            }
        } else {
            // rename/move
            @rename($srcResolved, $dstResolved);
        }

        return response('', 204);
    }

    // =========================================================================
    // RAW — download file or directory archive
    // =========================================================================

    public function raw(Request $request, string $path = '/'): BinaryFileResponse|StreamedResponse|Response
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return response('not found', 404);
        }

        $inline = $request->boolean('inline');

        // Single file
        if (is_file($resolved)) {
            $disposition = $inline ? 'inline' : 'attachment';
            return response()->download($resolved, basename($resolved), [
                'Content-Disposition' => $disposition . '; filename="' . basename($resolved) . '"',
                'Content-Security-Policy' => "script-src 'none';",
                'Cache-Control' => 'private',
            ]);
        }

        // Directory — ZIP archive
        $files = $request->query('files', '');
        $selectedFiles = $files ? explode(',', $files) : [];

        $zipName = basename($resolved) . '.zip';
        $tmpZip = tempnam(sys_get_temp_dir(), 'fb_zip_');

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::OVERWRITE) !== true) {
            return response('archive error', 500);
        }

        if (!empty($selectedFiles)) {
            foreach ($selectedFiles as $f) {
                $filePath = $this->resolve($root, $f);
                if (!$filePath) continue;
                if (is_dir($filePath)) {
                    $this->addDirToZip($zip, $filePath, basename($filePath));
                } else {
                    $zip->addFile($filePath, basename($filePath));
                }
            }
            $zipName = 'download.zip';
        } else {
            $this->addDirToZip($zip, $resolved, '');
        }

        $zip->close();

        return response()->download($tmpZip, $zipName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    // =========================================================================
    // PREVIEW — image thumbnail
    // =========================================================================

    public function preview(Request $request, string $size, string $path): BinaryFileResponse|Response
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved || !is_file($resolved)) {
            return response('not found', 404);
        }

        return response()->file($resolved, [
            'Content-Type' => mime_content_type($resolved) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // =========================================================================
    // SEARCH
    // =========================================================================

    public function search(Request $request, string $path = '/'): JsonResponse
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved || !is_dir($resolved)) {
            return response()->json([]);
        }

        $query = $request->query('query', '');
        if (empty($query)) {
            return response()->json([]);
        }

        $results = [];
        $this->searchDir($resolved, $root, strtolower($query), $results, 0, 100);

        return response()->json($results);
    }

    // =========================================================================
    // USAGE — disk space
    // =========================================================================

    public function usage(Request $request, string $path = '/'): JsonResponse
    {
        $root = $this->getRootPath($request);
        $resolvedRoot = realpath($root);
        if (!$resolvedRoot) {
            return response()->json(['total' => 0, 'used' => 0]);
        }

        $total = disk_total_space($resolvedRoot) ?: 0;
        $free = disk_free_space($resolvedRoot) ?: 0;
        $used = (int) trim(shell_exec('du -sb ' . escapeshellarg($resolvedRoot) . ' 2>/dev/null | cut -f1') ?: '0');

        return response()->json(['total' => $total, 'used' => $used]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function getRootPath(Request $request): string
    {
        $resolver = config('filebrowser.root_resolver');
        if (is_callable($resolver)) {
            return $resolver($request);
        }

        // Default: user's home directory
        $user = auth()->user();
        if (method_exists($user, 'getFileBrowserRoot')) {
            return $user->getFileBrowserRoot();
        }

        return '/home/' . ($user->username ?? $user->name ?? 'default');
    }

    protected function resolve(string $root, string $path): ?string
    {
        $path = '/' . ltrim($path, '/');
        $full = $root . $path;

        if (!file_exists($full)) return null;

        $resolved = realpath($full);
        $resolvedRoot = realpath($root);
        if (!$resolved || !$resolvedRoot || !str_starts_with($resolved, $resolvedRoot)) {
            return null;
        }

        return $resolved;
    }

    protected function buildPath(string $root, string $path): ?string
    {
        $path = '/' . ltrim($path, '/');
        $full = $root . $path;
        $parent = dirname($full);

        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        $resolvedParent = realpath($parent);
        $resolvedRoot = realpath($root);
        if (!$resolvedParent || !$resolvedRoot || !str_starts_with($resolvedParent, $resolvedRoot)) {
            return null;
        }

        return $resolvedParent . '/' . basename($full);
    }

    protected function fileInfo(string $path, string $root): array
    {
        $isDir = is_dir($path);
        $resolvedRoot = realpath($root);
        $resolvedPath = realpath($path);
        $relativePath = $resolvedPath === $resolvedRoot ? '/' : substr($resolvedPath, strlen($resolvedRoot));

        $info = [
            'name' => basename($path),
            'size' => $isDir ? 0 : (int) filesize($path),
            'extension' => $isDir ? '' : strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'modified' => date('c', filemtime($path)),
            'mode' => decoct(fileperms($path) & 0777),
            'isDir' => $isDir,
            'isSymlink' => is_link($path),
            'type' => $isDir ? 'directory' : $this->detectType($path),
            'path' => $relativePath ?: '/',
            'url' => str_replace('//', '/', config('filebrowser.prefix') . '/files' . $relativePath),
        ];

        if ($isDir) {
            $items = @scandir($path);
            $info['numDirs'] = 0;
            $info['numFiles'] = 0;
            if ($items) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') continue;
                    is_dir($path . '/' . $item) ? $info['numDirs']++ : $info['numFiles']++;
                }
            }
        }

        return $info;
    }

    protected function detectType(string $path): string
    {
        $mime = @mime_content_type($path) ?: 'application/octet-stream';
        if (str_starts_with($mime, 'text/')) return 'text';
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (str_starts_with($mime, 'video/')) return 'video';
        if ($mime === 'application/pdf') return 'pdf';
        if (in_array($mime, ['application/json', 'application/javascript', 'application/xml', 'application/x-httpd-php', 'application/x-sh'])) return 'text';

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $textExts = ['php', 'js', 'ts', 'jsx', 'tsx', 'vue', 'css', 'scss', 'less', 'html', 'htm', 'xml', 'json', 'yaml', 'yml', 'md', 'txt', 'log', 'ini', 'conf', 'env', 'sh', 'py', 'rb', 'go', 'java', 'c', 'cpp', 'h', 'rs', 'sql', 'htaccess', 'gitignore', 'dockerfile', 'toml', 'csv', 'svg', 'twig', 'blade'];
        if (in_array($ext, $textExts)) return 'text';

        return 'blob';
    }

    protected function deleteDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    protected function copyDir(string $src, string $dst): void
    {
        @mkdir($dst, 0755, true);
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            is_dir($s) ? $this->copyDir($s, $d) : @copy($s, $d);
        }
    }

    protected function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            $entry = $prefix ? $prefix . '/' . $item : $item;
            if (is_dir($path)) {
                $zip->addEmptyDir($entry);
                $this->addDirToZip($zip, $path, $entry);
            } else {
                $zip->addFile($path, $entry);
            }
        }
    }

    protected function searchDir(string $dir, string $root, string $pattern, array &$results, int $depth, int $limit): void
    {
        if (count($results) >= $limit || $depth > 20) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            if (count($results) >= $limit) return;
            $fullPath = $dir . '/' . $item;
            if (stripos($item, $pattern) !== false) {
                $results[] = $this->fileInfo($fullPath, $root);
            }
            if (is_dir($fullPath)) {
                $this->searchDir($fullPath, $root, $pattern, $results, $depth + 1, $limit);
            }
        }
    }
}
