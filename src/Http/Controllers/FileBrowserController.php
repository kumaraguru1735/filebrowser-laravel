<?php

namespace FileBrowser\Http\Controllers;

use FileBrowser\Models\FileBrowserShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileBrowserController extends Controller
{
    // =========================================================================
    // PERMISSION HELPER
    // =========================================================================

    protected function checkPerm(string $permission): void
    {
        $perms = config('filebrowser.permissions', []);
        if (!empty($perms) && isset($perms[$permission]) && $perms[$permission] === false) {
            abort(403, "Permission denied: {$permission}");
        }
    }

    // =========================================================================
    // CACHE-CONTROL HELPER
    // =========================================================================

    protected function withCacheHeaders(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    // =========================================================================
    // FRONTEND — serve Vue SPA
    // =========================================================================

    public function index(Request $request)
    {
        return view('filebrowser::browser', [
            'name' => config('filebrowser.name', 'File Browser'),
            'baseURL' => rtrim(config('filebrowser.prefix', '/file-browser'), '/'),
            'staticURL' => asset('vendor/filebrowser'),
        ]);
    }

    // =========================================================================
    // AUTH — Laravel session-based (replaces Go JWT)
    // =========================================================================

    public function login(Request $request): Response
    {
        $user = auth()->user();
        if (!$user) {
            return response('unauthorized', 401);
        }

        $token = $this->generateJwt($user);
        return response($token, 200)
            ->header('Content-Type', 'text/plain')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function renew(Request $request): Response
    {
        // Fix #5216: validate the existing token before renewing
        $existingToken = $request->header('X-Auth', '');
        if ($existingToken) {
            $parts = explode('.', $existingToken);
            if (count($parts) === 3) {
                $secret = config('app.key', 'filebrowser-laravel-secret');
                $validSig = $this->base64url(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true));
                if (!hash_equals($validSig, $parts[2])) {
                    return response('invalid token', 401);
                }
                // Check expiry
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if ($payload && isset($payload['exp']) && $payload['exp'] < time()) {
                    // Token expired — still renew if session is valid (Laravel auth handles this)
                }
            }
        }
        return $this->login($request);
    }

    /**
     * Generate a JWT token matching Go filebrowser's format.
     * The Vue frontend decodes this with jwt-decode library.
     */
    protected function generateJwt($user): string
    {
        $header = $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64url(json_encode([
            'user' => [
                'id' => $user->id,
                'username' => $user->name ?? $user->email ?? 'user',
                'locale' => 'en',
                'viewMode' => 'list',
                'singleClick' => false,
                'perm' => [
                    'admin' => method_exists($user, 'isAdmin') && $user->isAdmin(),
                    'execute' => false,
                    'create' => config('filebrowser.permissions.create', true),
                    'rename' => config('filebrowser.permissions.rename', true),
                    'modify' => config('filebrowser.permissions.modify', true),
                    'delete' => config('filebrowser.permissions.delete', true),
                    'share' => config('filebrowser.permissions.share', true),
                    'download' => config('filebrowser.permissions.download', true),
                ],
                'commands' => [],
                'lockPassword' => true,
                'hideDotfiles' => false,
                'dateFormat' => false,
            ],
            'iss' => 'File Browser',
            'iat' => time(),
            'exp' => time() + 7200,
        ]));
        $secret = config('app.key', 'filebrowser-laravel-secret');
        $signature = $this->base64url(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        return $header . '.' . $payload . '.' . $signature;
    }

    protected function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // =========================================================================
    // RESOURCE GET — list directory, get file info, get file content
    // Mirrors: Go resource.go resourceGetHandler
    // =========================================================================

    public function resourceGet(Request $request, string $path = '/'): JsonResponse
    {
        // No permission check needed for resourceGet (always allowed)

        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return $this->withCacheHeaders(response()->json('not found', 404));
        }

        $info = $this->fileInfo($resolved, $root);

        // Directory listing
        if (is_dir($resolved)) {
            $items = [];
            foreach (scandir($resolved) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $childPath = $resolved . '/' . $name;
                // Fix #5627: skip inaccessible subfolders instead of failing the whole listing
                if (!is_readable($childPath)) continue;
                try {
                    $items[] = $this->fileInfo($childPath, $root);
                } catch (\Throwable $e) {
                    continue; // Skip files that can't be stat'd
                }
            }

            // PR #5832: optional directory sizes (expensive — only when requested)
            if ($request->boolean('dirSizes')) {
                foreach ($items as &$item) {
                    if ($item['isDir']) {
                        $dirPath = $this->resolve($root, $item['path']);
                        if ($dirPath) {
                            $item['size'] = (int) trim(shell_exec('du -sb ' . escapeshellarg($dirPath) . ' 2>/dev/null | cut -f1') ?: '0');
                        }
                    }
                }
                unset($item);
            }

            // Sort: directories first, then by field
            $sortBy = $request->query('sort', 'name');
            $orderAsc = $request->query('order', 'asc') === 'asc';
            usort($items, function ($a, $b) use ($sortBy, $orderAsc) {
                if ($a['isDir'] !== $b['isDir']) return $a['isDir'] ? -1 : 1;
                $cmp = match ($sortBy) {
                    'size' => $a['size'] <=> $b['size'],
                    'modified' => strtotime($a['modified']) <=> strtotime($b['modified']),
                    default => strnatcasecmp($a['name'], $b['name']),
                };
                return $orderAsc ? $cmp : -$cmp;
            });

            $info['items'] = $items;
            $info['numDirs'] = count(array_filter($items, fn($i) => $i['isDir']));
            $info['numFiles'] = count(array_filter($items, fn($i) => !$i['isDir']));
            $info['sorting'] = ['by' => $sortBy, 'asc' => $orderAsc];

            return $this->withCacheHeaders(response()->json($info));
        }

        // File — include content for text files
        // Fix #5891: check download permission before returning binary file content
        if ($info['type'] !== 'text') {
            $this->checkPerm('download');
        }

        // Fix #5294: cap at 5MB to prevent memory exhaustion DoS
        $maxContentSize = min(5 * 1024 * 1024, (int) config('filebrowser.max_content_size', 5 * 1024 * 1024));
        if ($info['type'] === 'text' && $info['size'] < $maxContentSize) {
            if ($request->header('X-Encoding') === 'true') {
                $info['content'] = base64_encode(file_get_contents($resolved));
            } else {
                $info['content'] = file_get_contents($resolved);
            }
        }

        // Checksums (Go: ?checksum=md5|sha1|sha256|sha512)
        if ($checksum = $request->query('checksum')) {
            $algos = ['md5', 'sha1', 'sha256', 'sha512'];
            if (in_array($checksum, $algos)) {
                $info['checksums'] = [$checksum => hash_file($checksum, $resolved)];
            }
        }

        return $this->withCacheHeaders(
            response()->json($info)->header('ETag', $this->etag($resolved))
        );
    }

    // =========================================================================
    // RESOURCE POST — upload file or create directory
    // Mirrors: Go resource.go resourcePostHandler
    // =========================================================================

    public function resourcePost(Request $request, string $path = '/'): JsonResponse|Response
    {
        $this->checkPerm('create');

        $root = $this->getRootPath($request);
        $fullPath = $this->buildPath($root, $path);
        if (!$fullPath) {
            return response()->json('forbidden', 403);
        }

        $dirMode = config('filebrowser.dir_mode', 0755);
        $fileMode = config('filebrowser.file_mode', 0644);

        // PR #5658: quota check before upload
        $this->checkQuota($root, $request->header('Content-Length', 0));

        // Create directory (path ends with /)
        if (str_ends_with($path, '/')) {
            @mkdir($fullPath, $dirMode, true);
            $this->fireHook('upload', $fullPath, $root);
            return response('', 200);
        }

        $override = $request->boolean('override');
        if (file_exists($fullPath) && !$override) {
            return response('conflict', 409);
        }
        if (file_exists($fullPath) && $override) {
            // Need modify permission — check blocked extensions
            $this->validateExtension($fullPath);
            $this->clearThumbnailCache($fullPath);
        }

        $this->validateExtension($fullPath);

        // Handle multipart file upload
        if ($request->hasFile('files')) {
            $dir = is_dir($fullPath) ? $fullPath : dirname($fullPath);
            if (!is_dir($dir)) @mkdir($dir, $dirMode, true);
            foreach ($request->file('files') as $file) {
                $name = preg_replace('/[\/\\\0]/', '', $file->getClientOriginalName());
                if (empty($name) || $name === '.' || $name === '..') continue;
                $this->validateExtension($dir . '/' . $name);
                $file->move($dir, $name);
                @chmod($dir . '/' . $name, $fileMode);
            }
            $this->fireHook('upload', $dir, $root);
            return response('', 200);
        }

        // Raw body upload (Go-style: body is file content)
        $dir = dirname($fullPath);
        if (!is_dir($dir)) @mkdir($dir, $dirMode, true);
        file_put_contents($fullPath, $request->getContent());
        clearstatcache(true, $fullPath);
        @chmod($fullPath, $fileMode);

        $this->fireHook('upload', $fullPath, $root);

        return response('', 201)
            ->header('ETag', $this->etag($fullPath));
    }

    // =========================================================================
    // RESOURCE PUT — save/update existing file
    // Mirrors: Go resource.go resourcePutHandler
    // =========================================================================

    public function resourcePut(Request $request, string $path = '/'): Response
    {
        $this->checkPerm('modify');

        if (str_ends_with($path, '/')) {
            return response('method not allowed', 405);
        }

        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved || is_dir($resolved)) {
            return response('not found', 404);
        }

        file_put_contents($resolved, $request->getContent());
        clearstatcache(true, $resolved);

        $this->fireHook('save', $resolved, $root);

        return response('', 200)
            ->header('ETag', $this->etag($resolved));
    }

    // =========================================================================
    // RESOURCE DELETE
    // Mirrors: Go resource.go resourceDeleteHandler
    // =========================================================================

    public function resourceDelete(Request $request, string $path = '/'): Response
    {
        $this->checkPerm('delete');

        if ($path === '/' || $path === '') {
            return response('forbidden', 403);
        }

        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return response('not found', 404);
        }

        // Delete associated shares (like Go does)
        $relativePath = $this->relativePath($resolved, $root);
        FileBrowserShare::where('path', 'LIKE', $relativePath . '%')
            ->where('user_id', auth()->id())
            ->delete();

        // Delete thumbnail cache
        $this->clearThumbnailCache($resolved);

        if (is_dir($resolved)) {
            $this->deleteDir($resolved);
        } else {
            @unlink($resolved);
        }

        $this->fireHook('delete', $resolved, $root);

        return response('', 204);
    }

    // =========================================================================
    // RESOURCE PATCH — copy or rename/move
    // Mirrors: Go resource.go resourcePatchHandler
    // =========================================================================

    public function resourcePatch(Request $request, string $path = '/'): Response
    {
        $root = $this->getRootPath($request);
        $action = $request->query('action', 'rename');
        $destination = urldecode($request->query('destination', ''));
        $override = $request->boolean('override');
        $rename = $request->boolean('rename');

        // Fix #5306: normalize paths — strip trailing slashes, clean double slashes
        $path = rtrim($path, '/') ?: '/';
        $destination = rtrim($destination, '/') ?: '/';

        // Handle chmod action (separate flow — doesn't need destination)
        if ($action === 'chmod') {
            return $this->handleChmod($request, $root, $path);
        }

        // Permission check based on action
        if ($action === 'copy') {
            $this->checkPerm('create');
        } else {
            $this->checkPerm('rename');
        }

        if (empty($destination)) {
            return response('destination required', 400);
        }

        $srcResolved = $this->resolve($root, $path);
        if (!$srcResolved) {
            return response('source not found', 404);
        }

        $dstResolved = $this->buildPath($root, $destination);
        if (!$dstResolved) {
            return response('invalid destination', 403);
        }

        // Fix #5683: allow copy/move TO root (destination '/' is the user's root, not filesystem root)
        // Only block moving a file to itself
        if ($srcResolved === $dstResolved) {
            return response('source and destination are the same', 400);
        }

        // Prevent source being parent of destination (would create infinite recursion)
        if (is_dir($srcResolved) && str_starts_with(realpath(dirname($dstResolved)) . '/', realpath($srcResolved) . '/')) {
            return response('cannot move into itself', 403);
        }

        // Handle conflicts
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

        $dirMode = config('filebrowser.dir_mode', 0755);
        $dstDir = dirname($dstResolved);
        if (!is_dir($dstDir)) @mkdir($dstDir, $dirMode, true);

        if ($action === 'copy') {
            if (is_dir($srcResolved)) {
                $this->copyDir($srcResolved, $dstResolved);
            } else {
                @copy($srcResolved, $dstResolved);
            }
            $this->fireHook('copy', $srcResolved, $root, $dstResolved);
        } else {
            // rename/move
            $this->clearThumbnailCache($srcResolved);
            @rename($srcResolved, $dstResolved);
            $this->fireHook('rename', $srcResolved, $root, $dstResolved);
        }

        return response('', 204);
    }

    // =========================================================================
    // EXTRACT — Unpack zip/tar/tar.gz/tar.bz2 archives in place
    // =========================================================================

    public function extract(Request $request, string $path = '/'): Response
    {
        $this->checkPerm('create');
        $root = $this->getRootPath($request);
        $path = rtrim($path, '/') ?: '/';
        $resolved = $this->resolve($root, $path);

        if (!$resolved || !is_file($resolved)) {
            return response('archive not found', 404);
        }

        // User-chosen destination (set by the frontend's destination picker)
        $destination = urldecode($request->query('destination', ''));
        $override = $request->boolean('override');

        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $basename = pathinfo($resolved, PATHINFO_FILENAME);
        $dirMode = config('filebrowser.dir_mode', 0755);

        if (!empty($destination)) {
            // Resolve user-chosen destination relative to root
            $destDir = $this->buildPath($root, rtrim($destination, '/') ?: '/');
            if (!$destDir) {
                return response('invalid destination', 403);
            }
            // Reject path traversal
            if (preg_match('/\.\.\//', $destination) || strpos($destination, '..') !== false) {
                return response('invalid destination', 403);
            }
            if (!is_dir($destDir)) {
                if (!@mkdir($destDir, $dirMode, true)) {
                    return response('failed to create destination', 500);
                }
            } elseif (!$override) {
                // Conflict: append (1) (2) suffix unless override is set
                $base = $destDir;
                $i = 1;
                while (is_dir($destDir) && count(scandir($destDir)) > 2) {
                    $destDir = $base . ' (' . $i . ')';
                    $i++;
                }
                if (!is_dir($destDir)) {
                    @mkdir($destDir, $dirMode, true);
                }
            }
        } else {
            // No destination — extract to folder named after archive
            $dir = dirname($resolved);
            $destDir = $dir . '/' . $basename;
            $i = 1;
            while (is_dir($destDir)) {
                $destDir = $dir . '/' . $basename . ' (' . $i . ')';
                $i++;
            }
            if (!@mkdir($destDir, $dirMode, true)) {
                return response('failed to create extraction directory', 500);
            }
        }

        $createdNew = empty($destination);

        try {
            if ($ext === 'zip') {
                $this->extractZip($resolved, $destDir, $root);
            } elseif (in_array($ext, ['tar', 'tgz', 'gz', 'bz2', 'xz'])) {
                $this->extractTar($resolved, $destDir, $root);
            } else {
                if ($createdNew) @rmdir($destDir);
                return response('unsupported archive format: ' . $ext, 400);
            }
        } catch (\Throwable $e) {
            // Cleanup only if we created the folder
            if ($createdNew) $this->removeDir($destDir);
            return response('extraction failed: ' . $e->getMessage(), 500);
        }

        // Set ownership to match destination's parent
        $parentDir = dirname($destDir);
        $owner = @fileowner($parentDir);
        $group = @filegroup($parentDir);
        if ($owner !== false && $group !== false) {
            @exec('chown -R ' . escapeshellarg($owner . ':' . $group) . ' ' . escapeshellarg($destDir));
        }

        $this->fireHook('extract', $resolved, $root, $destDir);

        return response()->json([
            'success' => true,
            'destination' => str_replace($root, '', $destDir),
        ]);
    }

    private function extractZip(string $archive, string $destDir, string $root): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('cannot open zip');
        }

        // Pre-validate entries against path traversal
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, '..') !== false || str_starts_with($name, '/')) {
                $zip->close();
                throw new \RuntimeException('archive contains unsafe paths');
            }
        }

        if (!$zip->extractTo($destDir)) {
            $zip->close();
            throw new \RuntimeException('extraction failed');
        }
        $zip->close();
    }

    private function extractTar(string $archive, string $destDir, string $root): void
    {
        // Pre-validate entries
        $listOutput = [];
        $listExit = 0;
        @exec('tar -tf ' . escapeshellarg($archive) . ' 2>&1', $listOutput, $listExit);
        if ($listExit !== 0) {
            throw new \RuntimeException('cannot read archive');
        }
        foreach ($listOutput as $entry) {
            if (strpos($entry, '..') !== false || str_starts_with($entry, '/')) {
                throw new \RuntimeException('archive contains unsafe paths');
            }
        }

        // Extract with safety flags
        $extractOutput = [];
        $extractExit = 0;
        @exec('tar -xf ' . escapeshellarg($archive)
            . ' -C ' . escapeshellarg($destDir)
            . ' --no-same-owner --no-same-permissions 2>&1', $extractOutput, $extractExit);

        if ($extractExit !== 0) {
            throw new \RuntimeException('extraction failed: ' . implode(' ', $extractOutput));
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // =========================================================================
    // RAW — download file or directory archive
    // Mirrors: Go raw.go rawHandler
    // =========================================================================

    public function raw(Request $request, string $path = '/')
    {
        $this->checkPerm('download');

        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return response('not found', 404);
        }

        $inline = $request->boolean('inline');

        // Single file download
        if (is_file($resolved)) {
            $disposition = $inline ? 'inline' : 'attachment';
            $filename = basename($resolved);
            $headers = [
                'Content-Disposition' => $disposition . '; filename*=utf-8\'\'' . rawurlencode($filename),
                'Content-Security-Policy' => "script-src 'none';",
                'Cache-Control' => 'private',
            ];

            // Fix: disable scripted content in potentially dangerous file types for inline preview
            if ($inline) {
                $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
                if (in_array($ext, ['epub', 'svg', 'html', 'htm', 'xhtml'])) {
                    $headers['X-Content-Type-Options'] = 'nosniff';
                }
            }

            return response()->download($resolved, $filename, $headers);
        }

        // Directory — create archive
        $algo = $request->query('algo', 'zip');
        $files = $request->query('files', '');
        $selectedFiles = $files ? explode(',', $files) : [];

        if ($algo === 'zip') {
            return $this->archiveZip($root, $resolved, $selectedFiles);
        }

        // tar variants
        return $this->archiveTar($root, $resolved, $selectedFiles, $algo);
    }

    private function archiveZip(string $root, string $dir, array $selectedFiles)
    {
        $zipName = basename($dir) . '.zip';
        $tmp = tempnam(sys_get_temp_dir(), 'fb_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        if (!empty($selectedFiles)) {
            foreach ($selectedFiles as $f) {
                $p = $this->resolve($root, $f);
                if (!$p) continue;
                is_dir($p) ? $this->addDirToZip($zip, $p, basename($p)) : $zip->addFile($p, basename($p));
            }
            $zipName = 'download.zip';
        } else {
            $this->addDirToZip($zip, $dir, '');
        }

        $zip->close();
        return response()->download($tmp, $zipName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    private function archiveTar(string $root, string $dir, array $selectedFiles, string $algo)
    {
        $extMap = ['tar' => '.tar', 'targz' => '.tar.gz', 'tarbz2' => '.tar.bz2', 'tarxz' => '.tar.xz', 'tarlz4' => '.tar.lz4', 'tarsz' => '.tar.sz', 'tarbr' => '.tar.br', 'tarzst' => '.tar.zst'];
        $ext = $extMap[$algo] ?? '.tar.gz';

        $tmp = tempnam(sys_get_temp_dir(), 'fb_tar_') . $ext;

        // Pipe-based compression for formats not natively supported by tar
        $pipeCmd = match ($algo) {
            'tarlz4' => 'tar cf - -C ' . escapeshellarg($dir) . ' . | lz4 > ' . escapeshellarg($tmp),
            'tarsz'  => 'tar cf - -C ' . escapeshellarg($dir) . ' . | snzip > ' . escapeshellarg($tmp),
            'tarbr'  => 'tar cf - -C ' . escapeshellarg($dir) . ' . | brotli > ' . escapeshellarg($tmp),
            'tarzst' => 'tar cf - -C ' . escapeshellarg($dir) . ' . | zstd > ' . escapeshellarg($tmp),
            default  => null,
        };

        if ($pipeCmd !== null) {
            $cmd = $pipeCmd . ' 2>&1';
        } else {
            $compFlag = match ($algo) {
                'tar' => '', 'targz' => 'z', 'tarbz2' => 'j', 'tarxz' => 'J',
                default => 'z',
            };
            $cmd = 'tar -c' . $compFlag . 'f ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg($dir) . ' . 2>&1';
        }

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($tmp)) {
            return response('archive failed', 500);
        }

        $name = basename($dir) . $ext;
        return response()->download($tmp, $name)->deleteFileAfterSend(true);
    }

    // =========================================================================
    // PREVIEW — image thumbnails with caching
    // Mirrors: Go preview.go previewHandler
    // =========================================================================

    public function preview(Request $request, string $size, string $path)
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved || !is_file($resolved)) {
            return response('not found', 404);
        }

        $mime = @mime_content_type($resolved);
        if (!$mime || !str_starts_with($mime, 'image/')) {
            return response()->file($resolved, ['Content-Type' => $mime ?: 'application/octet-stream']);
        }

        // Thumbnail sizes (matching Go: thumb=256, big=1080)
        $maxDim = $size === 'thumb' ? 256 : 1080;

        // Cache thumbnails — key includes size, stable prefix for glob-based invalidation
        $cacheDir = storage_path('app/filebrowser/thumbnails/' . $size);
        $cacheKey = md5($resolved . $size) . '-' . filemtime($resolved);
        $cachePath = $cacheDir . '/' . $cacheKey . '.jpg';

        if (file_exists($cachePath)) {
            return response()->file($cachePath, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        // Try to resize with GD
        if (extension_loaded('gd')) {
            $info = @getimagesize($resolved);
            if ($info && ($info[0] > $maxDim || $info[1] > $maxDim)) {
                // Fix #2078: support more image formats for thumbnails
                $img = match ($info['mime']) {
                    'image/jpeg' => @imagecreatefromjpeg($resolved),
                    'image/png' => @imagecreatefrompng($resolved),
                    'image/gif' => null, // GIFs served as-is (animated)
                    'image/bmp', 'image/x-ms-bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($resolved) : null,
                    'image/webp' => @imagecreatefromwebp($resolved),
                    default => null,
                };

                if ($img) {
                    $ratio = min($maxDim / $info[0], $maxDim / $info[1]);
                    $newW = (int) ($info[0] * $ratio);
                    $newH = (int) ($info[1] * $ratio);
                    $thumb = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newW, $newH, $info[0], $info[1]);

                    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
                    imagejpeg($thumb, $cachePath, 85);
                    imagedestroy($img);
                    imagedestroy($thumb);

                    return response()->file($cachePath, [
                        'Content-Type' => 'image/jpeg',
                        'Cache-Control' => 'public, max-age=86400',
                    ]);
                }
            }
        }

        // Fallback: serve original
        return response()->file($resolved, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // =========================================================================
    // SEARCH — streaming NDJSON search
    // Mirrors: Go search.go searchHandler
    // =========================================================================

    public function search(Request $request, string $path = '/'): StreamedResponse|JsonResponse
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path ?: '/');
        if (!$resolved || !is_dir($resolved)) {
            return $this->withCacheHeaders(response()->json([]));
        }

        $query = $request->query('query', '');
        if (empty($query)) {
            return $this->withCacheHeaders(response()->json([]));
        }

        // Parse search conditions (Go-style: type:image, case:sensitive)
        $conditions = [];
        $searchTerm = $query;
        if (preg_match('/type:(\w+)/', $query, $m)) {
            $conditions['type'] = $m[1];
            $searchTerm = trim(str_replace($m[0], '', $searchTerm));
        }
        $caseSensitive = false;
        if (preg_match('/case:sensitive/', $query)) {
            $caseSensitive = true;
            $searchTerm = trim(str_replace('case:sensitive', '', $searchTerm));
        }

        return response()->stream(function () use ($resolved, $root, $searchTerm, $conditions, $caseSensitive) {
            echo "\n"; // heartbeat
            if (ob_get_level()) ob_flush();
            flush();
            $this->streamSearch($resolved, $root, $searchTerm, $conditions, $caseSensitive, 0, 200);
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function streamSearch(string $dir, string $root, string $pattern, array $conditions, bool $caseSensitive, int $depth, int $limit, int &$count = 0): void
    {
        if ($count >= $limit || $depth > 20) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            if ($count >= $limit) return;
            $fullPath = $dir . '/' . $item;
            $matches = $caseSensitive ? str_contains($item, $pattern) : stripos($item, $pattern) !== false;
            if ($matches) {
                $info = $this->fileInfo($fullPath, $root);
                if (!isset($conditions['type']) || $info['type'] === $conditions['type']) {
                    echo json_encode($info) . "\n";
                    if (ob_get_level()) ob_flush();
                    flush();
                    $count++;
                }
            }
            if (is_dir($fullPath)) {
                $this->streamSearch($fullPath, $root, $pattern, $conditions, $caseSensitive, $depth + 1, $limit, $count);
            }
        }
    }

    // =========================================================================
    // USAGE — disk space
    // Mirrors: Go resource.go diskUsage
    // =========================================================================

    public function usage(Request $request, string $path = '/'): JsonResponse
    {
        $root = $this->getRootPath($request);
        $resolvedRoot = realpath($root);
        if (!$resolvedRoot) {
            return $this->withCacheHeaders(response()->json(['total' => 0, 'used' => 0]));
        }

        $total = disk_total_space($resolvedRoot) ?: 0;
        $used = (int) trim(shell_exec('du -sb ' . escapeshellarg($resolvedRoot) . ' 2>/dev/null | cut -f1') ?: '0');

        return $this->withCacheHeaders(response()->json(['total' => $total, 'used' => $used]));
    }

    // =========================================================================
    // SHARES — CRUD for share links
    // Mirrors: Go share.go
    // =========================================================================

    public function shareList(Request $request): JsonResponse
    {
        $user = auth()->user();
        $isAdmin = method_exists($user, 'isAdmin') && $user->isAdmin();

        $query = FileBrowserShare::query();
        if (!$isAdmin) {
            $query->where('user_id', $user->id);
        }

        $shares = $query->orderBy('user_id')->orderBy('expires_at')->get();

        return $this->withCacheHeaders(response()->json($shares->map(fn($s) => [
            'hash' => $s->hash,
            'path' => $s->path,
            'expire' => $s->expires_at ? $s->expires_at->timestamp : 0,
            'userID' => $s->user_id,
            'passwordHash' => $s->password_hash ? '***' : '',
            'token' => $s->token ?? '',
        ])));
    }

    public function shareGet(Request $request, string $path = '/'): JsonResponse
    {
        $shares = FileBrowserShare::where('user_id', auth()->id())
            ->where('path', '/' . ltrim($path, '/'))
            ->get();
        return $this->withCacheHeaders(response()->json($shares->map(fn($s) => [
            'hash' => $s->hash,
            'path' => $s->path,
            'expire' => $s->expires_at ? $s->expires_at->timestamp : 0,
            'userID' => $s->user_id,
            'passwordHash' => $s->password_hash ? '***' : '',
            'token' => $s->token ?? '',
        ])));
    }

    public function shareCreate(Request $request, string $path = '/'): JsonResponse
    {
        // Fix #5835/PR #5875: sharing requires both share AND download permissions
        $this->checkPerm('share');
        $this->checkPerm('download');

        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return $this->withCacheHeaders(response()->json('not found', 404));
        }

        // Fix #5239: prevent sharing the root directory (too broad)
        $resolvedRoot = realpath($root);
        if ($resolved === $resolvedRoot) {
            return $this->withCacheHeaders(response()->json('Cannot share the root directory. Share a specific file or subdirectory instead.', 403));
        }

        $password = $request->input('password', '');
        $expires = (int) $request->input('expires', 0);
        $unit = $request->input('unit', 'hours');

        // Generate hash (6 bytes, base64url)
        $hash = rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');

        // Calculate expiration
        $expiresAt = null;
        if ($expires > 0) {
            $seconds = match ($unit) {
                'seconds' => $expires,
                'minutes' => $expires * 60,
                'hours' => $expires * 3600,
                'days' => $expires * 86400,
                default => $expires * 3600,
            };
            $expiresAt = now()->addSeconds($seconds);
        }

        $passwordHash = '';
        $token = '';
        if (!empty($password)) {
            $passwordHash = Hash::make($password);
            $token = rtrim(strtr(base64_encode(random_bytes(96)), '+/', '-_'), '=');
        }

        $share = FileBrowserShare::create([
            'hash' => $hash,
            'path' => '/' . ltrim($path, '/'),
            'user_id' => auth()->id(),
            'password_hash' => $passwordHash ?: null,
            'token' => $token ?: null,
            'expires_at' => $expiresAt,
        ]);

        return $this->withCacheHeaders(response()->json([
            'hash' => $share->hash,
            'path' => $share->path,
            'expire' => $share->expires_at ? $share->expires_at->timestamp : 0,
            'userID' => $share->user_id,
            'passwordHash' => $share->password_hash ? '***' : '',
            'token' => $token,
        ]));
    }

    public function shareDelete(Request $request, string $hash): Response
    {
        $share = FileBrowserShare::where('hash', $hash)->first();
        if (!$share) {
            return response('not found', 404);
        }
        if ($share->user_id !== auth()->id()) {
            return response('forbidden', 403);
        }
        $share->delete();
        return response('', 204);
    }

    // =========================================================================
    // PUBLIC SHARE ACCESS (no auth required)
    // Mirrors: Go public.go
    // =========================================================================

    public function publicShare(Request $request, string $hash, string $path = '/'): JsonResponse|Response
    {
        $share = FileBrowserShare::where('hash', $hash)->first();
        if (!$share || $share->isExpired()) {
            return response('not found', 404);
        }

        // Password check
        if ($share->password_hash) {
            $token = $request->query('token', $request->header('X-Share-Token', ''));
            if ($token !== $share->token) {
                return response('unauthorized', 401);
            }
        }

        $root = $this->getRootPathForUser($share->user_id);
        if (!$root) {
            return response('not found', 404);
        }

        $sharePath = $share->path;
        $fullPath = rtrim($sharePath, '/') . '/' . ltrim($path, '/');
        $resolved = $this->resolve($root, $fullPath);
        if (!$resolved) {
            return response('not found', 404);
        }

        return $this->withCacheHeaders(response()->json($this->fileInfo($resolved, $root)));
    }

    public function publicDownload(Request $request, string $hash, string $path = '/')
    {
        $share = FileBrowserShare::where('hash', $hash)->first();
        if (!$share || $share->isExpired()) {
            return response('not found', 404);
        }

        if ($share->password_hash) {
            $token = $request->query('token', $request->header('X-Share-Token', ''));
            if ($token !== $share->token) {
                return response('unauthorized', 401);
            }
        }

        // Fix #5888: verify share owner still has download permission
        $ownerPerms = config('filebrowser.permissions', []);
        if (!empty($ownerPerms) && isset($ownerPerms['download']) && $ownerPerms['download'] === false) {
            return response('share owner no longer has download permission', 403);
        }

        $root = $this->getRootPathForUser($share->user_id);
        if (!$root) {
            return response('not found', 404);
        }

        $fullPath = rtrim($share->path, '/') . '/' . ltrim($path, '/');
        $resolved = $this->resolve($root, $fullPath);
        if (!$resolved) {
            return response('not found', 404);
        }

        // Directory download — create ZIP archive
        if (is_dir($resolved)) {
            $zipName = basename($resolved) . '.zip';
            $tmp = tempnam(sys_get_temp_dir(), 'fb_zip_');
            $zip = new \ZipArchive();
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            $this->addDirToZip($zip, $resolved, '');
            $zip->close();
            return response()->download($tmp, $zipName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
        }

        return response()->download($resolved, basename($resolved));
    }

    // =========================================================================
    // SUBTITLE — convert SRT/ASS to WebVTT
    // Mirrors: Go subtitle.go
    // =========================================================================

    public function subtitle(Request $request, string $path)
    {
        $root = $this->getRootPath($request);
        $resolved = $this->resolve($root, $path);
        if (!$resolved || !is_file($resolved)) {
            return response('not found', 404);
        }

        $content = file_get_contents($resolved);
        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));

        // Already WebVTT
        if ($ext === 'vtt') {
            return response($content)->header('Content-Type', 'text/vtt; charset=utf-8');
        }

        // Convert SRT to WebVTT
        if ($ext === 'srt') {
            $vtt = "WEBVTT\n\n" . preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $content);
            return response($vtt)->header('Content-Type', 'text/vtt; charset=utf-8');
        }

        // Convert ASS/SSA to WebVTT (basic)
        if ($ext === 'ass' || $ext === 'ssa') {
            $vtt = "WEBVTT\n\n";
            preg_match_all('/Dialogue:\s*\d+,(\d+:\d{2}:\d{2}\.\d{2}),(\d+:\d{2}:\d{2}\.\d{2}),.*?,,.*?,,.*?,,.*?,(.+)/m', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $start = str_replace('.', ',', $m[1]) . '0';
                $end = str_replace('.', ',', $m[2]) . '0';
                $text = preg_replace('/\{[^}]*\}/', '', $m[3]);
                $text = str_replace('\N', "\n", $text);
                $vtt .= str_replace(',', '.', $start) . ' --> ' . str_replace(',', '.', $end) . "\n" . $text . "\n\n";
            }
            return response($vtt)->header('Content-Type', 'text/vtt; charset=utf-8');
        }

        return response('unsupported format', 400);
    }

    // =========================================================================
    // HEALTH
    // =========================================================================

    public function health(): JsonResponse
    {
        return $this->withCacheHeaders(response()->json(['status' => 'OK']));
    }

    // =========================================================================
    // TUS — Resumable Upload Protocol
    // =========================================================================

    public function tusPost(Request $request, string $path = '/'): Response
    {
        $this->checkPerm('create');

        // Fix #5848: normalize double slashes in path
        $path = preg_replace('#/+#', '/', $path);

        $root = $this->getRootPath($request);
        $fullPath = $this->buildPath($root, $path);
        if (!$fullPath) {
            return response('forbidden', 403);
        }

        $this->validateExtension($fullPath);

        // Fix #5834/PR #5876: reject negative Upload-Length to prevent inconsistent cache
        $rawLength = $request->header('Upload-Length');
        if ($rawLength === null || $rawLength === '') {
            return response('Upload-Length header required', 400);
        }
        $uploadLength = (int) $rawLength;
        if ($uploadLength <= 0) {
            return response('Upload-Length must be a positive integer', 400);
        }
        // Sanity cap: 10GB max per upload
        $maxUpload = (int) config('filebrowser.max_upload_size', 10 * 1024 * 1024 * 1024);
        if ($uploadLength > $maxUpload) {
            return response('Upload-Length exceeds maximum allowed size', 413);
        }

        $dirMode = config('filebrowser.dir_mode', 0755);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) @mkdir($dir, $dirMode, true);

        // Create empty file
        file_put_contents($fullPath, '');
        clearstatcache(true, $fullPath);

        // Store TUS metadata
        $tusId = md5($fullPath . microtime(true));
        $tusData = [
            'path' => $fullPath,
            'length' => $uploadLength,
            'offset' => 0,
            'created' => time(),
        ];
        file_put_contents('/tmp/fb_tus_' . $tusId, json_encode($tusData));

        $prefix = rtrim(config('filebrowser.prefix', '/file-browser'), '/');
        $location = $prefix . '/api/tus/' . $tusId;

        return response('', 201)
            ->header('Location', $location)
            ->header('Tus-Resumable', '1.0.0')
            ->header('Upload-Offset', '0');
    }

    public function tusHead(Request $request, string $id): Response
    {
        $tusFile = '/tmp/fb_tus_' . $id;
        if (!file_exists($tusFile)) {
            return response('not found', 404);
        }

        $tusData = json_decode(file_get_contents($tusFile), true);
        if (!$tusData) {
            return response('not found', 404);
        }

        return response('', 200)
            ->header('Tus-Resumable', '1.0.0')
            ->header('Upload-Offset', (string) $tusData['offset'])
            ->header('Upload-Length', (string) $tusData['length'])
            ->header('Cache-Control', 'no-store');
    }

    public function tusPatch(Request $request, string $id): Response
    {
        $tusFile = '/tmp/fb_tus_' . $id;
        if (!file_exists($tusFile)) {
            return response('not found', 404);
        }

        $tusData = json_decode(file_get_contents($tusFile), true);
        if (!$tusData) {
            return response('not found', 404);
        }

        $requestOffset = (int) $request->header('Upload-Offset', -1);
        if ($requestOffset !== $tusData['offset']) {
            return response('conflict', 409);
        }

        $chunk = $request->getContent();
        $chunkSize = strlen($chunk);

        if ($tusData['offset'] + $chunkSize > $tusData['length']) {
            return response('exceeds upload length', 400);
        }

        // Append chunk to file
        $fp = fopen($tusData['path'], 'ab');
        if (!$fp) {
            return response('write error', 500);
        }
        fwrite($fp, $chunk);
        fclose($fp);
        clearstatcache(true, $tusData['path']);

        $tusData['offset'] += $chunkSize;
        file_put_contents($tusFile, json_encode($tusData));

        // Upload complete
        if ($tusData['offset'] >= $tusData['length']) {
            @unlink($tusFile);

            $fileMode = config('filebrowser.file_mode', 0644);
            @chmod($tusData['path'], $fileMode);

            $root = $this->getRootPath($request);
            $this->fireHook('upload', $tusData['path'], $root);
        }

        return response('', 204)
            ->header('Tus-Resumable', '1.0.0')
            ->header('Upload-Offset', (string) $tusData['offset']);
    }

    public function tusDelete(Request $request, string $id): Response
    {
        $tusFile = '/tmp/fb_tus_' . $id;
        if (!file_exists($tusFile)) {
            return response('not found', 404);
        }

        $tusData = json_decode(file_get_contents($tusFile), true);
        if ($tusData && isset($tusData['path']) && file_exists($tusData['path'])) {
            @unlink($tusData['path']);
        }
        @unlink($tusFile);

        return response('', 204)
            ->header('Tus-Resumable', '1.0.0');
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    protected function getRootPath(Request $request): string
    {
        $resolver = config('filebrowser.root_resolver');
        if (is_callable($resolver)) {
            return $resolver($request);
        }
        $user = auth()->user();
        if ($user && method_exists($user, 'getFileBrowserRoot')) {
            return $user->getFileBrowserRoot();
        }
        return '/home/' . ($user->username ?? $user->name ?? 'default');
    }

    protected function getRootPathForUser(int $userId): ?string
    {
        $user = \App\Models\User::find($userId);
        if (!$user) return null;
        if (method_exists($user, 'getFileBrowserRoot')) {
            return $user->getFileBrowserRoot();
        }
        return '/home/' . ($user->username ?? $user->name ?? 'default');
    }

    protected function resolve(string $root, string $path): ?string
    {
        $path = '/' . ltrim($path, '/');

        // Fix #5889: reject paths containing '..' to enforce directory boundary
        if (preg_match('/\.\./', $path)) {
            return null;
        }

        $full = $root . $path;
        if (!file_exists($full)) return null;
        $resolved = realpath($full);
        $resolvedRoot = realpath($root);
        if (!$resolved || !$resolvedRoot || !str_starts_with($resolved, $resolvedRoot)) return null;
        return $resolved;
    }

    protected function buildPath(string $root, string $path): ?string
    {
        $path = '/' . ltrim($path, '/');
        $full = $root . $path;
        $parent = dirname($full);
        $dirMode = config('filebrowser.dir_mode', 0755);
        if (!is_dir($parent)) @mkdir($parent, $dirMode, true);
        $resolvedParent = realpath($parent);
        $resolvedRoot = realpath($root);
        if (!$resolvedParent || !$resolvedRoot || !str_starts_with($resolvedParent, $resolvedRoot)) return null;
        return $resolvedParent . '/' . basename($full);
    }

    /**
     * PR #5658: Check disk quota before allowing upload.
     * Quota resolver can be set in config('filebrowser.quota_resolver').
     */
    protected function checkQuota(string $root, int $uploadSize = 0): void
    {
        $quotaResolver = config('filebrowser.quota_resolver');
        if (!is_callable($quotaResolver)) return;

        $quota = $quotaResolver(auth()->user(), $root); // Returns max bytes or 0 for unlimited
        if ($quota <= 0) return;

        $used = (int) trim(shell_exec('du -sb ' . escapeshellarg($root) . ' 2>/dev/null | cut -f1') ?: '0');
        if (($used + $uploadSize) > $quota) {
            abort(507, 'Disk quota exceeded. Used: ' . round($used / 1024 / 1024) . 'MB / ' . round($quota / 1024 / 1024) . 'MB');
        }
    }

    protected function relativePath(string $resolved, string $root): string
    {
        $resolvedRoot = realpath($root);
        return $resolvedRoot && str_starts_with($resolved, $resolvedRoot)
            ? substr($resolved, strlen($resolvedRoot)) ?: '/'
            : '/';
    }

    protected function fileInfo(string $path, string $root): array
    {
        $isDir = is_dir($path);
        $resolvedRoot = realpath($root);
        $resolvedPath = realpath($path);
        $relative = $resolvedPath === $resolvedRoot ? '/' : substr($resolvedPath, strlen($resolvedRoot));

        return [
            'name' => basename($path),
            'size' => $isDir ? 0 : (int) filesize($path),
            'extension' => $isDir ? '' : strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'modified' => date('c', filemtime($path)),
            'mode' => decoct(fileperms($path) & 0777),
            'isDir' => $isDir,
            'isSymlink' => is_link($path),
            'type' => $isDir ? 'directory' : $this->detectType($path),
            'path' => $relative ?: '/',
        ];
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
        $archiveExts = ['zip', 'gz', 'tar', 'bz2', 'xz', '7z', 'rar', 'lz4', 'zst'];
        if (in_array($ext, $archiveExts)) return 'archive';
        return 'blob';
    }

    protected function etag(string $path): string
    {
        $stat = stat($path);
        return $stat ? sprintf('"%x-%x"', $stat['mtime'], $stat['size']) : '';
    }

    protected function validateExtension(string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $blocked = config('filebrowser.blocked_extensions', ['phtml', 'phar']);
        if (in_array($ext, $blocked)) {
            abort(403, 'File type not allowed');
        }
    }

    protected function clearThumbnailCache(string $path): void
    {
        $cacheBase = storage_path('app/filebrowser/thumbnails');
        foreach (['thumb', 'big'] as $size) {
            $dir = $cacheBase . '/' . $size;
            if (!is_dir($dir)) continue;
            // Find and delete all cache files for this path (any mtime)
            $prefix = substr(md5($path . $size), 0, 8);
            foreach (glob($dir . '/' . $prefix . '*.jpg') as $f) @unlink($f);
        }
    }

    protected function fireHook(string $event, string $path, string $root, string $destination = ''): void
    {
        $hooks = config('filebrowser.hooks.' . $event, []);
        if (empty($hooks)) return;

        $env = [
            'FILE' => $path,
            'SCOPE' => $root,
            'TRIGGER' => $event,
            'USERNAME' => auth()->user()->name ?? 'unknown',
        ];
        if ($destination) $env['DESTINATION'] = $destination;

        foreach ($hooks as $cmd) {
            $envStr = implode(' ', array_map(fn($k, $v) => escapeshellarg($k) . '=' . escapeshellarg($v), array_keys($env), $env));
            exec("env {$envStr} {$cmd} 2>&1 &");
        }
    }

    protected function deleteDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->deleteDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * Copy directory recursively.
     * PR #5873: deep conflict resolution — merge into existing dirs,
     * only overwrite files that conflict (preserve non-conflicting).
     */
    protected function copyDir(string $src, string $dst): void
    {
        $dirMode = config('filebrowser.dir_mode', 0755);
        if (!is_dir($dst)) {
            @mkdir($dst, $dirMode, true);
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            if (is_dir($s)) {
                // Recursively merge into existing subdirectory
                $this->copyDir($s, $d);
            } else {
                // Copy file (overwrites if exists)
                @copy($s, $d);
            }
        }
    }

    protected function addDirToZip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            $e = $prefix ? $prefix . '/' . $item : $item;
            if (is_dir($p)) { $zip->addEmptyDir($e); $this->addDirToZip($zip, $p, $e); }
            else { $zip->addFile($p, $e); }
        }
    }

    /**
     * @deprecated Use streamSearch() instead — kept for backward compatibility
     */
    protected function searchDir(string $dir, string $root, string $pattern, array $conditions, bool $caseSensitive, array &$results, int $depth, int $limit): void
    {
        if (count($results) >= $limit || $depth > 20) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            if (count($results) >= $limit) return;
            $fullPath = $dir . '/' . $item;
            $matches = $caseSensitive ? str_contains($item, $pattern) : stripos($item, $pattern) !== false;

            if ($matches) {
                $info = $this->fileInfo($fullPath, $root);
                // Type filter
                if (isset($conditions['type']) && $info['type'] !== $conditions['type']) {
                    $matches = false;
                }
                if ($matches) $results[] = $info;
            }
            if (is_dir($fullPath)) {
                $this->searchDir($fullPath, $root, $pattern, $conditions, $caseSensitive, $results, $depth + 1, $limit);
            }
        }
    }

    // =========================================================================
    // CHMOD — change file permissions (recursive optional)
    // =========================================================================

    private function handleChmod(Request $request, string $root, string $path): Response
    {
        $this->checkPerm('modify');

        $resolved = $this->resolve($root, $path);
        if (!$resolved) {
            return response('not found', 404);
        }

        // Permissions can come as octal string (e.g. "755") in body or query
        $body = json_decode($request->getContent(), true) ?: [];
        $permsStr = $body['permissions'] ?? $request->query('permissions', '');
        $recursive = !empty($body['recursive']) || $request->boolean('recursive');

        if (!preg_match('/^[0-7]{3,4}$/', $permsStr)) {
            return response('invalid permissions (expected 3-4 octal digits)', 400);
        }

        $mode = octdec($permsStr);

        if ($recursive && is_dir($resolved)) {
            $this->chmodRecursive($resolved, $mode);
        } else {
            if (!@chmod($resolved, $mode)) {
                return response('chmod failed', 500);
            }
        }

        $this->fireHook('chmod', $resolved, $root);
        return response('', 204);
    }

    private function chmodRecursive(string $dir, int $mode): void
    {
        @chmod($dir, $mode);
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->chmodRecursive($path, $mode);
            } else {
                @chmod($path, $mode);
            }
        }
    }

    // =========================================================================
    // SETTINGS — stub for frontend Settings page (returns sane defaults)
    // =========================================================================

    public function settingsGet(Request $request)
    {
        return response()->json([
            'signup' => false,
            'createUserDir' => false,
            'hideLoginButton' => true,
            'minimumPasswordLength' => 8,
            'userHomeBasePath' => '',
            'authMethod' => 'json',
            'rules' => [],
            'shell' => [],
            'commands' => (object)[],
            'tus' => [
                'chunkSize' => 10 * 1024 * 1024,
                'retryCount' => 5,
            ],
            'branding' => [
                'name' => config('app.name', 'VPanel') . ' File Manager',
                'disableExternal' => true,
                'disableUsedPercentage' => false,
                'files' => '',
                'theme' => 'light',
                'color' => '#673ab7',
            ],
            'defaults' => [
                'scope' => '/',
                'locale' => 'en',
                'viewMode' => 'list',
                'singleClick' => false,
                'redirectAfterCopyMove' => false,
                'sorting' => ['by' => 'name', 'asc' => true],
                'perm' => [
                    'admin' => false,
                    'execute' => false,
                    'create' => true,
                    'rename' => true,
                    'modify' => true,
                    'delete' => true,
                    'share' => true,
                    'download' => true,
                ],
                'commands' => [],
                'hideDotfiles' => false,
                'dateFormat' => false,
                'aceEditorTheme' => 'github',
            ],
        ]);
    }

    public function settingsPut(Request $request)
    {
        // VPanel manages settings server-side; client-side updates ignored
        return response('', 204);
    }

    // =========================================================================
    // USERS — stub for frontend (single virtual user = current Laravel user)
    // =========================================================================

    public function usersList(Request $request)
    {
        $user = auth()->user();
        if (!$user) return response('unauthorized', 401);

        return response()->json([$this->virtualUser($user, $request)]);
    }

    public function usersGet(Request $request, string $id)
    {
        $user = auth()->user();
        if (!$user) return response('unauthorized', 401);

        return response()->json($this->virtualUser($user, $request));
    }

    private function virtualUser($user, Request $request): array
    {
        $root = $this->getRootPath($request);
        return [
            'id' => $user->id,
            'username' => $user->email ?? $user->name ?? 'user',
            'password' => '',
            'scope' => $root,
            'locale' => 'en',
            'lockPassword' => true,
            'viewMode' => 'list',
            'singleClick' => false,
            'perm' => [
                'admin' => false,
                'execute' => false,
                'create' => true,
                'rename' => true,
                'modify' => true,
                'delete' => true,
                'share' => true,
                'download' => true,
            ],
            'commands' => [],
            'sorting' => ['by' => 'name', 'asc' => true],
            'rules' => [],
            'hideDotfiles' => false,
            'dateFormat' => false,
            'redirectAfterCopyMove' => false,
            'aceEditorTheme' => 'github',
        ];
    }
}
