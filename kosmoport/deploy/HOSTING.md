# Подготовка публикации Космопорта

Локальные инструменты публикации готовы. Этот ход не изменяет GitHub, FTP, магазин, CMS или рабочую БД. Скрипт пишет только в /htdocs/kosmoport/; отдельное явное действие перенаправления касается только /htdocs/cms/sites/kosmosfera/.htaccess. Файл /htdocs/.htaccess магазина не является целью.

## Файлы и границы

- deploy/ftp-release.py: Python 3.10+, стандартная библиотека и Pillow 12.3.0 для WebP. Без --apply только проверяет локальный ZIP и печатает план; FTP не открывает. При --apply использует FTP_PASS из окружения, фиксированные ftpupload.net:21 / if0_42771076, проверяемый TLS и защищённый канал данных. FTP_HOST/FTP_USER нельзя незаметно заменить окружением.
- deploy/github-workflow.yml: инертный шаблон для .github/workflows/deploy-kosmoport.yml существующего ivankirillov414-art/profisport-site; исходник приложения в kosmoport/. PR запускает только тесты/пакет. FTP разрешён только успешному push main исходного репозитория. Секрет FTP_PASSWORD доступен лишь последнему FTP-шагу как FTP_PASS. Автоматического redirect или включения документов нет.
- deploy/hosting-workflow-notes.md: необходимые минимальные правки старых workflows, точные команды реальных PHP/MySQL тестов и ограничения локальной проверки YAML.
- tests/migration-ftp.py и results JSON: изолированные in-memory FTPS проверки; настоящие соединения заблокированы. PHP-проверка загрузчика использует искусственный CMS config и отключённую сеть, не настоящие учётные записи.

## Перед импортом в родительский GitHub

Сначала применить одновременно правки из hosting-workflow-notes.md: добавить kosmoport/** и .github/workflows/deploy-kosmoport.yml в paths-ignore старого deploy-infinityfree.yml; добавить kosmoport/** в его FTP exclude; сохранить остальные исключения. Установить общей FTP-группе infinityfree-deploy cancel-in-progress:false, включая участвующие CMS/motion writers. Иначе старый root upload или cancel-in-progress могут затронуть новый процесс. Это предложения; здесь старые workflows не редактировались.

Добавить ignore для runtime/fixtures.private, *.private.json/php, локальных БД/архивов/логов, __pycache__, бизнес-хранилищ и deploy/artifacts/. Не выполнять слепой git add всей рабочей папки. В source commit допустимы код, схемы, искусственные тесты и публичные установочные метаданные; реальные документы, выгрузки, браузерные сессии и локальные тестовые пароли не включаются. Новый workflow дополнительно отказывает отслеживаемым опасным путям.

## Источник, конфиг и первая установка

Пакет сохраняет app/public/config/migrations/bin/storage соседями. bin включён и защищён. Source ZIP проверяется по внешнему SHA256, манифесту, каждому файлу и типу source-only; ссылки, обходы путей, runtime/данные/неизвестные server-control файлы запрещены. WebP проверяются ещё локально до FTP, даже при точном совпадении байтов.

На первом размещении config/config.php строится из source config/infinityfree.example.php. На сервере этот загрузчик читает только db_* из уже существующего /htdocs/cms/private/config.php. Он не читает таблицы/аккаунты CMS и не копирует пароль или хеш старого пользователя. App key создаётся серверным PHP random_bytes(32), сохраняется отдельно в приватном app-key.php через lock и атомарное размещение, затем стабильно переиспользуется. При обновлениях config/config.php, ключ и рабочие документы не заменяются.

Координатор предоставляет deploy/install-public.json без исходной одноразовой строки:

```json
{"format":"kosmoport-install-public-v1","setup_key_sha256":"SHA256_НОВОЙ_НЕЗАВИСИМОЙ_СЛУЧАЙНОЙ_СТРОКИ","base_path":"/kosmoport","contains_secret":false}
```

Значение хеша должно быть ровно 64 lowercase hex; пример выше намеренно невалиден для запуска. Сама строка хранится отдельно, не в Git/ZIP/CI logs/URL. Legacy формат с единственным setup_key_hash также распознаётся для совместимости тестов. Иные поля, дубли ключей, plaintext token и contains_secret:true отвергаются. Обычный новый владелец создаётся через /kosmoport/setup.php с собственной почтой/паролем и одноразовым ключом, без импорта пользователя CMS.

Все три флага из текущего шаблона изначально закрыты: storage.allow_webroot_storage, storage.http_denial_verified и documents.allow_webroot_storage. До браузерного подтверждения они не включаются по наличию .htaccess или успешному FTP.

## Локальные команды

Из корня приложения (python3/php заменить на проверенные абсолютные пути интерпретаторов при необходимости):

```sh
python3 tests/migration-ftp.py
php tools/package.php --check
php tools/package.php --output deploy/artifacts/kosmoport-hosting-source-20261031.zip
php deploy/verify-source-package.php deploy/artifacts/kosmoport-hosting-source-20261031.zip
python3 deploy/ftp-release.py --package deploy/artifacts/kosmoport-hosting-source-20261031.zip --expected-sha256 SHA256_ИЗ_SIDECAR --report deploy/artifacts/plan.json
```

Последняя команда без --apply не подключается к хостингу. Повторно использовать существующий путь отчёта нельзя. Для локальной Windows проверки: Python находится в C:/Users/Izop300520251/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe; PHP — C:/Users/Izop300520251/Documents/ChatGPT/cfqn ghjabcgjhn/tools/php-runtime/php.exe с его ext/php_zip.dll. Не запускать GitHub/FTP режим только ради проверки шаблона.

## Первый разрешённый upload выполняет координатор

До передачи секрета скрипт проверяет source ZIP, Pillow/изображения и форму начального конфига/публичного хеша. Скрипт требует безопасный MLSD; нет небезопасного fallback, считающего ошибку 550 отсутствующим файлом. Непустой каталог без собственного правильного .kosmoport-deploy.json или с чужим маркером отклоняется.

Сначала резервируется владение небольшим несекретным claimed marker, чтобы обрыв не оставил нераспознаваемую папку. Затем корневой и все приватные .htaccess загружаются и побайтно сверяются ДО кода, конфига и canary. Каждый файл сначала загружается во временный файл в той же папке, проверяется, затем переименовывается и читается обратно. Изменённый или новый чужой destination не затирается: есть preflight и свежая проверка непосредственно перед rename. DELETE/RMD/sync-delete/clean-slate отсутствуют. Существующие config/storage данные сохраняются; служебные guards/canaries управляются явно.

Код, JSON, шрифты и .htaccess сверяются побайтно. Для WebP источник и ответ должны декодироваться как один кадр одинакового размера; максимум RMS отдельного RGBA канала <=5, а не среднее каналов. Отчёт хранит source/remote SHA и измеренные значения. Полный verified marker появляется только после успешного readback. Неоконченный claimed release можно продолжить только тем же просмотренным ZIP; смена пакета требует сначала разобраться с неоконченным выпуском. Между FTP compare и rename нет настоящего server-side CAS: общая CI serialization и отсутствие параллельных ручных FTP правок обязательны.

Команда для будущего разрешённого запуска, НЕ выполненная в этом ходе:

```sh
python3 deploy/ftp-release.py --package SOURCE.zip --expected-sha256 REVIEWED_SHA256 --apply --report PRIVATE_LOCAL_RELEASE_REPORT.json
```

FTP_PASS уже передаётся workflow из существующего secrets.FTP_PASSWORD; не включать пароль в аргументы команд, отчёты или source.

## Реальная браузерная проверка приватности

Ожидаемый origin зафиксирован как https://ferryffggg.infinityfreeapp.com по текущему CMS workflow. Другой alias/domain не считается тем же доказательством. Положительный контроль: настоящий browser GET публичного JSON из report.public_control.path (убрать начальное public/ для URL), HTTP200 и SHA256 исходных байтов ответа ровно report.public_control.sha256. JSON содержит instance_id, package_sha256 и свежий deployment_nonce; это не HTML challenge. Нельзя хешировать заново отформатированный JSON вместо исходного ответа.

В том же настоящем браузере после штатной проверки InfinityFree получить GET403/404 без ухода на другой URL для четырёх существующих/защищённых путей: report.privacy_probe.path, report.document_probe.path, config/config.php и app/bootstrap.php под /kosmoport/. Canary — обычные .txt в storage/tmp и storage/documents, не hidden файлы; они созданы и подтверждены FTP. Отсутствующий файл, сторонняя error page, challenge200, opaque/network error или просто наличие .htaccess не считаются защитой.

Координатор сохраняет приватный receipt только по фактически наблюдавшимся ответам. Поля: kind=interactive-browser-observation; instance_id/deployment_nonce/package_sha256 из текущего публичного контроля; guard_sha256 из отчёта; origin ровно ожидаемый HTTPS origin; checked_at ISO UTC после verified_at и не старше часа. private_gets содержит ровно 4 записи с path, полными url/final_url, method=GET, status=403 или404, challenge=false, network_error=false и ссылкой evidence на действительный browser trace/скрин. public_control содержит method=GET/status200/url/final_url/body_sha256/challenge=false/network_error=false/evidence для свежего публичного JSON. Не включать cookie/Authorization/пароль в receipt. Нельзя автоматически синтезировать этот файл из FTP или Python HTTP.

Отдельное действие после фактической проверки:

```sh
python3 deploy/ftp-release.py --package SOURCE.zip --expected-sha256 REVIEWED_SHA256 --privacy-proof PRIVATE_BROWSER_RECEIPT.json --confirm-browser-checks --apply --report PRIVATE_OPT_IN_REPORT.json
```

Это операторское подтверждение, не независимый детектор истинности наблюдений. Скрипт проверяет привязки/свежесть, текущие canary и все guard hashes и пишет только приватный config/documents-privacy.php. Новый deployment_nonce при КАЖДОЙ публикации делает предыдущее runtime разрешение недействительным, не стирая config/storage. Нужна новая браузерная проверка и явное включение. Проверка другого origin/path/policy не принимается. После этого пройти нормальный setup владельца и пользовательские сценарии настоящей CRM.

## Включение и аварийное снятие redirect

После успешного readback, браузерной проверки и setup можно отдельно активировать только root/index.html прежней Космосферы; этот режим НЕ повторяет deploy и не меняет nonce/принятое разрешение документов:

```sh
python3 deploy/ftp-release.py --package SOURCE.zip --expected-sha256 REVIEWED_SHA256 --redirect-kosmosfera --apply --report PRIVATE_REDIRECT_REPORT.json
```

Это ограниченный RewriteRule ^(?:index\.html)?$ /kosmoport/ [R=302,L] в именованном блоке /htdocs/cms/sites/kosmosfera/.htaccess. Другие директивы и файлы сохраняются, root .htaccess магазина не меняется. До включения проверяются обычные directory ancestors, собственный verified marker и совпадение source SHA. Для отката только своего точного блока:

```sh
python3 deploy/ftp-release.py --package SOURCE.zip --expected-sha256 REVIEWED_SHA256 --remove-kosmosfera-redirect --apply --report PRIVATE_REDIRECT_ROLLBACK_REPORT.json
```

Отключение работает даже при потерянной/повреждённой новой папке или маркере; исходные CRLF/BOM и остальные директивы восстанавливаются побайтно. Изменённый вручную или неоднозначный managed block не удаляется автоматически. Этот откат не чистит сайт, БД или файлы магазина.

## Основания и ограничения проверки

Reference: существующий [CMS workflow](https://github.com/ivankirillov414-art/profisport-site/blob/main/.github/workflows/deploy-cms.yml) с отдельным readback и максимальным per-channel RMS; [текущий storefront workflow](https://github.com/ivankirillov414-art/profisport-site/blob/main/.github/workflows/deploy-infinityfree.yml) подтверждён read-only на main. FTPS использует [FTP_TLS](https://docs.python.org/3/library/ftplib.html) и [проверяемый SSL context](https://docs.python.org/3/library/ssl.html). Никаких GitHub/FTP мутаций при этом чтении не было.

Локально пройдены offline FTPS/PHP семантические тесты и source package checks. GitHub Actions, настоящий InfinityFree/MLSD/TLS/browser403, production setup и касса/сканер/печать этим не проверены. Полного YAML/actionlint/Bash линтера в доступном runtime не найдено; workflow проверен структурно и по встроенным Python/PHP фрагментам, первый PR-run ещё должен подтвердить Linux/PHP8.2/Actions. Все FTP writers должны получить общую concurrency до активации.
