<?php
declare(strict_types=1);

final class CrmDocumentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}

function crm_documents_error(string $code, string $message, int $status = 422): never
{
    throw new CrmDocumentException($code, $message, $status);
}

function crm_documents_text(mixed $value, int $length, string $label, bool $empty = false): string
{
    if (!is_string($value) || strlen($value) > $length * 4 || !preg_match('//u', $value) || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
        crm_documents_error('invalid_field', 'Некорректное поле: ' . $label . '.');
    }
    $value = trim($value);
    preg_match_all('/./us', $value, $characters);
    if ((!$empty && $value === '') || count($characters[0]) > $length) {
        crm_documents_error('invalid_field', 'Проверьте поле «' . $label . '».');
    }
    return $value;
}

function crm_documents_id(mixed $id): string
{
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
        crm_documents_error('invalid_document_id', 'Некорректный идентификатор документа.');
    }
    return $id;
}

function crm_documents_number(mixed $value, int $min, int $max, string $label): int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$value)) {
        crm_documents_error('invalid_number', 'Некорректное значение: ' . $label . '.');
    }
    $number = (int)$value;
    if ($number < $min || $number > $max) {
        crm_documents_error('invalid_number', 'Некорректное значение: ' . $label . '.');
    }
    return $number;
}

function crm_documents_filename(mixed $name): array
{
    $name = crm_documents_text($name, 200, 'Имя файла');
    if (strpbrk($name, "/\\:") !== false || str_starts_with($name, '.') || preg_match('/[. ]\z/u', $name)) {
        crm_documents_error('unsafe_filename', 'Имя файла не должно содержать путь или служебные символы.');
    }
    if (preg_match('/\.(?:php[0-9]?|phtml|phar|exe|dll|com|bat|cmd|ps1|vbs|js|jse|hta|html?|svg|lnk|scr)(?:\.|\z)/i', $name)) {
        crm_documents_error('unsafe_type', 'Исполняемые файлы, HTML и SVG загружать нельзя.');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
    ];
    if (!isset($types[$extension])) {
        crm_documents_error('unsupported_type', 'Допустимы PDF, DOCX, XLSX, PPTX, TXT и CSV.');
    }
    return ['filename' => $name, 'extension' => $extension, 'mime' => $types[$extension]];
}

function crm_documents_xml(string $xml): DOMDocument
{
    if (!class_exists(DOMDocument::class)) {
        crm_documents_error('validator_unavailable', 'На сервере не настроена проверка документов XML.', 503);
    }
    if ($xml === '' || strlen($xml) > 8 * 1024 * 1024 || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml)) {
        crm_documents_error('unsafe_office', 'Документ содержит неподдерживаемый XML.');
    }
    // A small compressed XML may allocate far more memory as DOM nodes and
    // attributes. Bound structural complexity before asking libxml to parse it.
    if (substr_count($xml, '<') + substr_count($xml, '=') > 60000) {
        crm_documents_error('office_too_complex', 'Структура документа слишком большая для загрузки.', 413);
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
            crm_documents_error('invalid_office', 'Структура документа Office повреждена.');
        }
        return $document;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function crm_documents_office(string $path, string $extension): void
{
    if (!class_exists(ZipArchive::class)) {
        crm_documents_error('validator_unavailable', 'На сервере не настроена проверка документов Office.', 503);
    }
    $spec = [
        'docx' => ['word/document.xml', 'document', 'wordprocessingml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
        'xlsx' => ['xl/workbook.xml', 'workbook', 'spreadsheetml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
        'pptx' => ['ppt/presentation.xml', 'presentation', 'presentationml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'],
    ][$extension];
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        crm_documents_error('invalid_office', 'Не удалось прочитать документ Office.');
    }
    try {
        if ($zip->numFiles < 3 || $zip->numFiles > 2000) {
            crm_documents_error('invalid_office', 'Неподдерживаемая структура документа Office.');
        }
        $seen = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                crm_documents_error('invalid_office', 'Повреждённый архив Office.');
            }
            $name = $stat['name'];
            $lower = strtolower($name);
            if (!preg_match('//u', $name) || strlen($name) > 500 || strpbrk($name, "\\:\0") !== false || str_starts_with($name, '/') || preg_match('~(?:^|/)\.\.?(/|$)|//~', $name) || isset($seen[$lower])) {
                crm_documents_error('unsafe_office', 'Архив Office содержит небезопасные пути.');
            }
            $seen[$lower] = true;
            $total += (int)$stat['size'];
            if ($total > 50 * 1024 * 1024 || $stat['size'] > 20 * 1024 * 1024 || ($stat['size'] > 1024 * 1024 && $stat['size'] > max(1, $stat['comp_size']) * 1000)) {
                crm_documents_error('office_too_large', 'Распакованный документ слишком большой.', 413);
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                crm_documents_error('unsafe_office', 'Зашифрованные документы Office не поддерживаются.');
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === 3 && (($attributes >> 16) & 0170000) === 0120000) {
                crm_documents_error('unsafe_office', 'Ссылки в архиве Office недопустимы.');
            }
            if (preg_match('~(?:^|/)(?:embeddings|activex|customui|macrosheets)(?:/|$)|vbaproject|vbadata|\.(?:exe|dll|com|bat|cmd|ps1|vbs|js|jse|hta|html?|svg|php[0-9]?|phtml|phar|lnk|scr)\z~i', $name)) {
                crm_documents_error('unsafe_office', 'Макросы, исполняемые и активные вложения в Office недопустимы.');
            }
            if (!str_ends_with($name, '/')) {
                $signature = $zip->getFromIndex($i, 8);
                if (!is_string($signature) || str_starts_with($signature, 'MZ') || str_starts_with($signature, "\x7fELF") || str_starts_with($signature, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")) {
                    crm_documents_error('unsafe_office', 'Исполняемый или составной объект скрыт внутри документа Office.');
                }
            }
            if (str_ends_with($lower, '.xml') || str_ends_with($lower, '.rels')) {
                if ($stat['size'] > 8 * 1024 * 1024) {
                    crm_documents_error('office_too_large', 'XML внутри документа слишком большой.', 413);
                }
                $xml = $zip->getFromIndex($i);
                if (!is_string($xml)) {
                    crm_documents_error('invalid_office', 'Не удалось прочитать структуру Office.');
                }
                $part = crm_documents_xml($xml);
                foreach ($part->getElementsByTagName('*') as $element) {
                    if (in_array(strtolower($element->localName), ['activex', 'oleobject', 'ddelink', 'control'], true) || preg_match('/macroEnabled|macrosheet|vbaProject|vbaData|activeX|oleObject/i', $element->getAttribute('ContentType')) || ($element->localName === 'Relationship' && preg_match('~/(?:vbaProject|vbaData|control|activeXControl|activeXControlBinary|oleObject|attachedTemplate|externalLink|xlMacrosheet|xlIntlMacrosheet)\z~i', rawurldecode($element->getAttribute('Type'))))) {
                        crm_documents_error('unsafe_office', 'Макросы и активные объекты в Office недопустимы.');
                    }
                }
                foreach (['f', 'definedName', 'instrText', 'fldSimple'] as $fieldType) {
                    foreach ($part->getElementsByTagNameNS('*', $fieldType) as $field) {
                        $instruction = $field->textContent;
                        if ($fieldType === 'fldSimple') {
                            foreach ($field->attributes as $attribute) {
                                if ($attribute->localName === 'instr') {
                                    $instruction .= ' ' . $attribute->value;
                                }
                            }
                        }
                        if (in_array($fieldType, ['f', 'definedName'], true) && (str_contains($instruction, '|') || preg_match('/(?:\b|_)(?:WEBSERVICE|RTD|CALL|REGISTER\s*\.\s*ID|EXEC)\s*\(/i', $instruction))) {
                            crm_documents_error('unsafe_office', 'Автоматические внешние функции Office недопустимы.');
                        }
                        if (in_array($fieldType, ['instrText', 'fldSimple'], true) && preg_match('/\b(?:DDEAUTO|DDE|INCLUDETEXT|INCLUDEPICTURE|LINK)\b/i', $instruction)) {
                            crm_documents_error('unsafe_office', 'Автоматические внешние поля Word недопустимы.');
                        }
                    }
                }
                if (str_ends_with($lower, '.rels')) {
                    foreach ($part->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
                        $mode = $relationship->getAttribute('TargetMode');
                        $target = $relationship->getAttribute('Target');
                        if (strcasecmp($mode, 'External') === 0) {
                            // Ordinary explicit web/mail links are allowed; external templates,
                            // spreadsheet connections, UNC paths and executable schemes are not.
                            if (!str_ends_with($relationship->getAttribute('Type'), '/hyperlink') || !preg_match('~\A(?:https?://|mailto:)[^\x00-\x20]+\z~iD', $target)) {
                                crm_documents_error('unsafe_office', 'Автоматические внешние связи в Office недопустимы.');
                            }
                        } else {
                            $targetPath = rawurldecode(explode('#', $target, 2)[0]);
                            if (strpbrk($targetPath, "\\:\0") !== false || str_starts_with($targetPath, '//') || preg_match('/[\x00-\x1f]/', $targetPath)) {
                                crm_documents_error('unsafe_office', 'Небезопасная связь в документе Office.');
                            }
                            if ($targetPath !== '') {
                                $base = dirname(dirname($name));
                                $segments = $base === '.' || str_starts_with($targetPath, '/') ? [] : explode('/', $base);
                                foreach (explode('/', ltrim($targetPath, '/')) as $segment) {
                                    if ($segment === '..') {
                                        if ($segments === []) {
                                            crm_documents_error('unsafe_office', 'Связь Office выходит за пределы архива.');
                                        }
                                        array_pop($segments);
                                    } elseif ($segment !== '.' && $segment !== '') {
                                        $segments[] = $segment;
                                    }
                                }
                                if ($zip->locateName(implode('/', $segments)) === false) {
                                    crm_documents_error('invalid_office', 'Связь Office указывает на отсутствующую часть документа.');
                                }
                            }
                        }
                    }
                }
            }
        }
        foreach (['[Content_Types].xml', '_rels/.rels', $spec[0]] as $required) {
            if ($zip->locateName($required) === false) {
                crm_documents_error('invalid_office', 'Формат Office не соответствует расширению файла.');
            }
        }
        $types = crm_documents_xml((string)$zip->getFromName('[Content_Types].xml'));
        $validType = false;
        foreach ($types->getElementsByTagNameNS('*', 'Override') as $override) {
            if ($override->getAttribute('PartName') === '/' . $spec[0] && $override->getAttribute('ContentType') === $spec[3]) {
                $validType = true;
            }
        }
        $rels = crm_documents_xml((string)$zip->getFromName('_rels/.rels'));
        $officeLinks = 0;
        foreach ($rels->getElementsByTagNameNS('*', 'Relationship') as $rel) {
            if (str_ends_with($rel->getAttribute('Type'), '/officeDocument')) {
                if ($rel->getAttribute('Target') !== $spec[0] || $rel->getAttribute('TargetMode') === 'External') {
                    crm_documents_error('invalid_office', 'Некорректная основная часть документа Office.');
                }
                $officeLinks++;
            }
        }
        $main = crm_documents_xml((string)$zip->getFromName($spec[0]));
        $root = $main->documentElement;
        $namespace = $root?->namespaceURI;
        $namespaces = ['http://schemas.openxmlformats.org/' . $spec[2] . '/2006/main', 'http://purl.oclc.org/ooxml/' . $spec[2] . '/main'];
        if (!$validType || $officeLinks !== 1 || $root?->localName !== $spec[1] || !in_array($namespace, $namespaces, true)) {
            crm_documents_error('invalid_office', 'Тип документа Office не подтверждён его содержимым.');
        }
    } finally {
        $zip->close();
    }
}

function crm_documents_validate_file(string $path, mixed $originalName, int $maxBytes): array
{
    $metadata = crm_documents_filename($originalName);
    if (is_link($path) || !is_file($path) || !is_readable($path)) {
        crm_documents_error('invalid_upload', 'Не удалось прочитать загруженный файл.');
    }
    $size = filesize($path);
    if ($size === false || $size < 1) {
        crm_documents_error('empty_file', 'Пустой файл загружать нельзя.');
    }
    if ($size > $maxBytes) {
        crm_documents_error('file_too_large', 'Файл превышает допустимый размер.', 413);
    }
    if (!class_exists(finfo::class)) {
        crm_documents_error('validator_unavailable', 'На сервере не настроена проверка типов файлов.', 503);
    }
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $head = (string)file_get_contents($path, false, null, 0, 32);
    if (in_array($metadata['extension'], ['docx', 'xlsx', 'pptx'], true)) {
        if (!in_array($detected, ['application/zip', $metadata['mime']], true) || !str_starts_with($head, "PK\x03\x04")) {
            crm_documents_error('mime_mismatch', 'Содержимое файла не соответствует формату Office.');
        }
        crm_documents_office($path, $metadata['extension']);
    } elseif ($metadata['extension'] === 'pdf') {
        $body = (string)file_get_contents($path);
        $names = preg_replace_callback('/#([a-f0-9]{2})/i', static fn(array $m): string => chr(hexdec($m[1])), $body);
        if ($detected !== 'application/pdf' || !preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)(?:\r|\n)/D', $body) || !preg_match('/%%EOF[\x00\x09\x0a\x0c\x0d\x20]*\z/D', $body) || !preg_match('/\/(?:Catalog|Pages)\b/', $names)) {
            crm_documents_error('invalid_pdf', 'Файл не является поддерживаемым документом PDF.');
        }
        if (preg_match('/\/(?:JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA|OpenAction|AA|Encrypt)\b/i', $names)) {
            crm_documents_error('unsafe_pdf', 'PDF со скриптами, шифрованием или активными вложениями не поддерживается.');
        }
        // Hidden dictionaries in compressed object streams cannot be safely
        // inspected by this dependency-free validator. Refuse rather than
        // claiming that an unchecked active object is a safe PDF.
        if (preg_match('/\/ObjStm\b/', $names)) {
            crm_documents_error('unsupported_pdf', 'Сохраните PDF без сжатых служебных объектов (например, PDF 1.4).');
        }
    } else {
        $body = (string)file_get_contents($path);
        if (!in_array($detected, ['text/plain', 'text/csv', 'application/csv'], true) || !preg_match('//u', $body) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $body) || preg_match('/<\?(?:php|=)|<!\s*(?:doctype|entity)|<\s*(?:html|script|svg|iframe)\b/i', $body)) {
            crm_documents_error('unsafe_text', 'Разрешён только обычный текст UTF-8 без исполняемой разметки.');
        }
        if ($metadata['extension'] === 'csv') {
            // Fail closed for spreadsheet formula execution when CSV is opened by staff.
            if (substr_count($body, "\n") > 100000) {
                crm_documents_error('csv_too_large', 'CSV содержит слишком много строк.', 413);
            }
            $offset = 0;
            while (($nextLine = strpos($body, "\n", $offset)) !== false) {
                if ($nextLine - $offset > 65536) {
                    crm_documents_error('csv_too_large', 'Строка CSV слишком длинная.', 413);
                }
                $offset = $nextLine + 1;
            }
            if (strlen($body) - $offset > 65536) {
                crm_documents_error('csv_too_large', 'Строка CSV слишком длинная.', 413);
            }
            foreach ([',', ';', "\t"] as $delimiter) {
                $stream = fopen($path, 'rb');
                try {
                    while (($row = fgetcsv($stream, 65536, $delimiter, '"', '')) !== false) {
                        if (count($row) > 1000) {
                            crm_documents_error('csv_too_large', 'CSV содержит слишком много столбцов.', 413);
                        }
                        foreach ($row as $cell) {
                            $cell = ltrim((string)$cell, " \t\r\n\xEF\xBB\xBF");
                            if (preg_match('/\A[=+@]/u', $cell) || (str_starts_with($cell, '-') && !preg_match('/\A-\d+(?:[.,]\d+)?\z/D', $cell))) {
                                crm_documents_error('unsafe_csv', 'CSV с исполняемыми формулами не поддерживается.');
                            }
                        }
                    }
                } finally {
                    fclose($stream);
                }
            }
        }
    }
    $sha256 = hash_file('sha256', $path);
    if (!is_string($sha256)) {
        crm_documents_error('invalid_upload', 'Не удалось проверить целостность файла.');
    }
    return $metadata + ['size_bytes' => $size, 'sha256' => $sha256];
}
