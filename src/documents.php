<?php
declare(strict_types=1);

/*
 * Documents: a library of spec sheets, brochures and the like in folders
 * (which can be sent with quotes), plus files kept against a customer or a
 * supplier. Files are stored privately under storage/documents and only
 * served to signed-in users who may see them.
 */

/** File types that can be uploaded, with the type they're served as. */
const DOC_TYPES = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    'csv' => 'text/csv', 'txt' => 'text/plain', 'rtf' => 'application/rtf',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'zip' => 'application/zip', 'msg' => 'application/vnd.ms-outlook', 'eml' => 'message/rfc822',
];
const DOC_MAX_BYTES = 25 * 1024 * 1024;
/** Most mail servers refuse messages much over 20 MB (attachments grow by a third when sent). */
const QUOTE_ATTACH_MAX_BYTES = 15 * 1024 * 1024;

function doc_folders(): array
{
    return db_all('SELECT f.*, (SELECT COUNT(*) FROM documents d WHERE d.folder_id = f.id) AS documents FROM doc_folders f ORDER BY f.sort, f.name');
}

function document_path(array $doc): string
{
    return storage_path('documents') . '/' . basename($doc['stored_name']);
}

/** A php.ini size such as "64M" in bytes. */
function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024,
        default => $n ?: PHP_INT_MAX,
    };
}

function file_size_label(int|string|null $bytes): string
{
    $b = (int)$bytes;
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
}

/** Library documents grouped by folder: [folder name => [docs]]. */
function library_documents(): array
{
    $out = [];
    foreach (db_all('SELECT d.*, f.name AS folder_name FROM documents d JOIN doc_folders f ON f.id = d.folder_id ORDER BY f.sort, f.name, d.title') as $d) {
        $out[$d['folder_name']][] = $d;
    }
    return $out;
}

function account_documents(int $accountId): array
{
    return db_all('SELECT d.*, u.name AS uploaded_by_name FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.account_id = ? ORDER BY d.created_at DESC', [$accountId]);
}

function supplier_documents(int $supplierId): array
{
    return db_all('SELECT d.*, u.name AS uploaded_by_name FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.supplier_id = ? ORDER BY d.created_at DESC', [$supplierId]);
}

/** Who may see / add / remove a document, by where it lives. */
function document_can(string $what, array $doc): bool
{
    if (!empty($doc['supplier_id'])) {
        return $what === 'view' ? can('suppliers.view') : can('suppliers.edit');
    }
    if (!empty($doc['account_id'])) {
        return match ($what) {
            'view'   => true,
            'add'    => can('customers.edit'),
            'delete' => can('records.delete') || (can('customers.edit') && (int)($doc['uploaded_by'] ?? 0) === (int)(current_user()['id'] ?? -1)),
            default  => false,
        };
    }
    return $what === 'view' || can('documents.manage');
}

/**
 * Save an uploaded file as a document. $where holds folder_id, account_id or supplier_id.
 * $moveUploaded is false for files that didn't come through a form upload (e.g. tests).
 */
function document_store(array $file, array $where, string $title = '', string $description = '', bool $moveUploaded = true): int
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new IntegrationException('"' . ($file['name'] ?? 'That file') . '" is larger than the server allows (' . ini_get('upload_max_filesize') . ').');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new IntegrationException('Choose a file to upload.');
    }
    $name = mb_substr(basename(str_replace('\\', '/', (string)$file['name'])), 0, 255);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(DOC_TYPES[$ext])) {
        throw new IntegrationException("\"$name\" isn't a type of file that can be uploaded. Use PDF, Word, Excel, PowerPoint, images, CSV, text or zip files.");
    }
    $size = (int)($file['size'] ?? filesize($file['tmp_name']));
    if ($size > DOC_MAX_BYTES) {
        throw new IntegrationException("\"$name\" is " . file_size_label($size) . '; files can be up to ' . file_size_label(DOC_MAX_BYTES) . '.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = storage_path('documents') . '/' . $stored;
    $ok = $moveUploaded ? move_uploaded_file($file['tmp_name'], $dest) : copy($file['tmp_name'], $dest);
    if (!$ok) {
        throw new IntegrationException('Couldn\'t save the file. Check the CRM\'s storage folder is writable.');
    }
    $title = trim($title) !== '' ? mb_substr(trim($title), 0, 190) : mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 190);
    db_exec('INSERT INTO documents (folder_id, account_id, supplier_id, title, description, file_name, stored_name, mime, size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $where['folder_id'] ?? null, $where['account_id'] ?? null, $where['supplier_id'] ?? null, $title, trim($description) !== '' ? mb_substr(trim($description), 0, 500) : null,
        $name, $stored, DOC_TYPES[$ext], $size, current_user()['id'] ?? null,
    ]);
    $id = (int)db()->lastInsertId();
    $place = document_place(db_one('SELECT * FROM documents WHERE id = ?', [$id]));
    audit('document_upload', "Document \"$title\" ($name, " . file_size_label($size) . ") added to $place", 'documents', $id, null, null,
        isset($where['account_id']) ? (int)$where['account_id'] : null);
    return $id;
}

/** Where a document is kept, in words. */
function document_place(array $doc): string
{
    if (!empty($doc['account_id'])) {
        return 'customer ' . db_value('SELECT name FROM accounts WHERE id = ?', [$doc['account_id']]);
    }
    if (!empty($doc['supplier_id'])) {
        return 'supplier ' . db_value('SELECT name FROM suppliers WHERE id = ?', [$doc['supplier_id']]);
    }
    return 'the ' . db_value('SELECT name FROM doc_folders WHERE id = ?', [$doc['folder_id']]) . ' folder';
}

function document_delete(array $doc): void
{
    db_exec('DELETE FROM documents WHERE id = ?', [$doc['id']]);
    @unlink(document_path($doc));
    audit('document_delete', "Document \"{$doc['title']}\" ({$doc['file_name']}) deleted from " . document_place($doc), 'documents', (int)$doc['id'], null, null,
        $doc['account_id'] ? (int)$doc['account_id'] : null);
}

/** Uploaded files from a form field that allows several ("files[]") as a list of single-file arrays. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return [];
    }
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if ((int)$f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** Documents chosen to go with a quote, and a check they aren't too big to email. */
function quote_documents(int $quoteId): array
{
    return db_all('SELECT d.* FROM quote_documents q JOIN documents d ON d.id = q.document_id WHERE q.quote_id = ? ORDER BY d.title', [$quoteId]);
}

/** Save which documents go with a quote (library documents, or the customer's own files). */
function quote_set_documents(array $quote, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $docs = [];
    foreach ($ids as $id) {
        $d = db_one('SELECT * FROM documents WHERE id = ? AND (folder_id IS NOT NULL OR account_id = ?)', [$id, $quote['account_id']]);
        if ($d) {
            $docs[] = $d;
        }
    }
    $total = array_sum(array_column($docs, 'size'));
    if ($total > QUOTE_ATTACH_MAX_BYTES) {
        throw new IntegrationException('The documents you picked add up to ' . file_size_label($total) . ', which is too big to email. Pick up to '
            . file_size_label(QUOTE_ATTACH_MAX_BYTES) . ' in total.');
    }
    db_exec('DELETE FROM quote_documents WHERE quote_id = ?', [$quote['id']]);
    foreach ($docs as $d) {
        db_exec('INSERT INTO quote_documents (quote_id, document_id) VALUES (?, ?)', [$quote['id'], $d['id']]);
    }
    return $docs;
}

/** Documents as email attachments. */
function document_attachments(array $docs): array
{
    $out = [];
    foreach ($docs as $d) {
        $path = document_path($d);
        if (!is_file($path)) {
            throw new IntegrationException("The file for \"{$d['title']}\" is missing from the server. Upload it again.");
        }
        $out[] = ['name' => $d['file_name'], 'path' => $path, 'mime' => $d['mime']];
    }
    return $out;
}

function documents_controller(): void
{
    $action = query('action', 'list');
    $id = query_int('id');
    $doc = $id ? db_one('SELECT * FROM documents WHERE id = ?', [$id]) : null;
    $back = safe_return($_POST['_return'] ?? query('return'), url('documents', array_filter(['folder' => query_int('folder')])));

    if ($action === 'download' || $action === 'open') {
        if (!$doc || !document_can('view', $doc)) {
            not_found('Document not found.');
        }
        $path = document_path($doc);
        if (!is_file($path)) {
            not_found('The file is missing from the server.');
        }
        // PDFs and images can open in the browser; everything else downloads.
        $inline = $action === 'open' && preg_match('#^(application/pdf|image/(png|jpeg|gif|webp))$#', (string)$doc['mime']);
        header('Content-Type: ' . ($doc['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $doc['file_name']) . '"'
            . "; filename*=UTF-8''" . rawurlencode($doc['file_name']));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        readfile($path);
        exit;
    }

    if (is_post()) {
        verify_csrf();
        try {
            switch ($action) {
                case 'upload':
                    $where = array_filter([
                        'folder_id' => ($f = (int)($_POST['folder_id'] ?? 0)) && db_value('SELECT 1 FROM doc_folders WHERE id = ?', [$f]) ? $f : null,
                        'account_id' => ($a = (int)($_POST['account_id'] ?? 0)) && db_value('SELECT 1 FROM accounts WHERE id = ?', [$a]) ? $a : null,
                        'supplier_id' => ($s = (int)($_POST['supplier_id'] ?? 0)) && db_value('SELECT 1 FROM suppliers WHERE id = ?', [$s]) ? $s : null,
                    ]);
                    if (count($where) !== 1) {
                        throw new IntegrationException('Choose a folder for the document.');
                    }
                    if (!document_can('add', $where)) {
                        forbidden();
                    }
                    $files = uploaded_files('files');
                    if (!$files) {
                        throw new IntegrationException('Choose a file to upload' . (empty($_FILES) && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 ? ' (it may be larger than the server allows)' : '') . '.');
                    }
                    $title = count($files) === 1 ? (string)($_POST['title'] ?? '') : '';
                    $saved = 0;
                    $problems = [];
                    foreach ($files as $file) {
                        try {
                            document_store($file, $where, $title, (string)($_POST['description'] ?? ''));
                            $saved++;
                        } catch (IntegrationException $e) {
                            $problems[] = $e->getMessage();
                        }
                    }
                    if ($saved) {
                        flash(($saved === 1 ? 'Document uploaded.' : "$saved documents uploaded.") . ($problems ? ' Not uploaded: ' . implode(' ', $problems) : ''), $problems ? 'error' : 'success');
                    } else {
                        throw new IntegrationException(implode(' ', $problems));
                    }
                    break;

                case 'edit':
                    if (!$doc || !document_can('add', $doc)) {
                        forbidden();
                    }
                    $title = trim((string)($_POST['title'] ?? ''));
                    if ($title === '') {
                        throw new IntegrationException('Give the document a name.');
                    }
                    $folder = $doc['folder_id'] ? (int)($_POST['folder_id'] ?? $doc['folder_id']) : null;
                    if ($folder !== null && !db_value('SELECT 1 FROM doc_folders WHERE id = ?', [$folder])) {
                        throw new IntegrationException('Choose a folder.');
                    }
                    $description = trim((string)($_POST['description'] ?? ''));
                    db_exec('UPDATE documents SET title = ?, description = ?, folder_id = ? WHERE id = ?',
                        [mb_substr($title, 0, 190), $description !== '' ? mb_substr($description, 0, 500) : null, $folder, $doc['id']]);
                    audit('document_update', "Document \"{$doc['title']}\" updated", 'documents', (int)$doc['id'], null, array_filter([
                        'Name' => $title !== $doc['title'] ? ['from' => $doc['title'], 'to' => $title] : null,
                        'Folder' => $folder !== null && $folder !== (int)$doc['folder_id']
                            ? ['from' => db_value('SELECT name FROM doc_folders WHERE id = ?', [$doc['folder_id']]), 'to' => db_value('SELECT name FROM doc_folders WHERE id = ?', [$folder])] : null,
                    ]) ?: null, $doc['account_id'] ? (int)$doc['account_id'] : null);
                    flash('Document updated.');
                    break;

                case 'delete':
                    if (!$doc || !document_can('delete', $doc)) {
                        forbidden();
                    }
                    document_delete($doc);
                    flash('Document deleted.');
                    break;

                case 'folder_save':
                    require_permission('documents.manage');
                    $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
                    $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255) ?: null;
                    if ($name === '' || mb_strlen($name) > 80) {
                        throw new IntegrationException('Give the folder a name (up to 80 characters).');
                    }
                    $folderId = (int)($_POST['folder_id'] ?? 0);
                    if (db_value('SELECT 1 FROM doc_folders WHERE name = ? AND id <> ?', [$name, $folderId])) {
                        throw new IntegrationException("There is already a folder called \"$name\".");
                    }
                    if ($folderId && ($old = db_one('SELECT * FROM doc_folders WHERE id = ?', [$folderId]))) {
                        db_exec('UPDATE doc_folders SET name = ?, description = ? WHERE id = ?', [$name, $description, $folderId]);
                        audit('doc_folder', "Document folder \"{$old['name']}\" updated", null, null, null,
                            ['Name' => ['from' => $old['name'], 'to' => $name], 'Description' => ['from' => (string)$old['description'], 'to' => (string)$description]]);
                        flash('Folder updated.');
                    } else {
                        db_exec('INSERT INTO doc_folders (name, description, sort) VALUES (?, ?, ?)', [$name, $description, (int)db_value('SELECT COALESCE(MAX(sort), 0) + 1 FROM doc_folders')]);
                        $folderId = (int)db()->lastInsertId();
                        audit('doc_folder', "Document folder \"$name\" created");
                        flash("Folder \"$name\" created.");
                    }
                    $back = url('documents', ['folder' => $folderId]);
                    break;

                case 'folder_delete':
                    require_permission('documents.manage');
                    $folder = db_one('SELECT * FROM doc_folders WHERE id = ?', [(int)($_POST['folder_id'] ?? 0)]) ?? not_found();
                    if (db_value('SELECT COUNT(*) FROM documents WHERE folder_id = ?', [$folder['id']])) {
                        throw new IntegrationException('Move or delete the documents in "' . $folder['name'] . '" first.');
                    }
                    db_exec('DELETE FROM doc_folders WHERE id = ?', [$folder['id']]);
                    audit('doc_folder', "Document folder \"{$folder['name']}\" deleted");
                    flash('Folder deleted.');
                    $back = url('documents');
                    break;

                default:
                    not_found();
            }
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect($back);
    }

    // The library.
    $folders = doc_folders();
    $folderId = query_int('folder');
    $folder = $folderId ? (array_values(array_filter($folders, fn($f) => (int)$f['id'] === $folderId))[0] ?? null) : null;
    $q = trim(query('q'));
    $where = ['d.folder_id IS NOT NULL'];
    $params = [];
    if ($folder) {
        $where[] = 'd.folder_id = ?';
        $params[] = $folder['id'];
    }
    if ($q !== '') {
        $where[] = '(d.title LIKE ? OR d.description LIKE ? OR d.file_name LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%");
    }
    $docs = db_all('SELECT d.*, f.name AS folder_name, u.name AS uploaded_by_name FROM documents d JOIN doc_folders f ON f.id = d.folder_id
        LEFT JOIN users u ON u.id = d.uploaded_by WHERE ' . implode(' AND ', $where) . ' ORDER BY f.sort, f.name, d.title', $params);
    page('documents', compact('folders', 'folder', 'docs', 'q'), $folder ? $folder['name'] : 'Documents');
}
