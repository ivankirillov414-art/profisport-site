# Иллюстрации категорий · 16 сентября 2026

Семь новых иллюстраций созданы встроенным image_gen. Версия `illustration-v1`.
Для сайта используются одинаковые локальные PNG 480 × 320 во всех браузерах, без emoji,
иконного шрифта, внешних CDN и выбора изображения по user-agent. Файлы оптимизированы
до 128 цветов; общий вес около 457 КиБ. Исходная HTML-разметка и динамический вывод
`buildCategoryTiles` используют те же пути. Подпись категории остаётся текстом ссылки,
изображение декоративное (`alt=""`).

## Общий промпт

Use case: stylized-concept. Asset: one website category illustration for a premium sporting goods store, part of a consistent seven-image family. Beautiful restrained 3D editorial product illustration, tactile matte surfaces, accurate simple product shapes, soft studio light from upper left, subtle short contact shadow. Palette exclusively warm yellow #F4CE38, graphite #242932, silver and off-white. Pure white #FFFFFF seamless background all the way to every edge; no backdrop shapes. Landscape 3:2 composition. Entire subject centered, occupies about 78 percent of width and 76 percent of height with generous clean white margins, never cropped. Eye-level slightly elevated three-quarter product view. No lettering, labels, logos, watermark, border, people, gradients in background or extra props. Not a tiny line icon. Subject: 

## Сюжеты и файлы

### accessories

[PNG](../assets/categories/accessories-illustration-v1.png)

A warm yellow bicycle helmet with graphite vents and a compact charcoal backpack with yellow zipper detail, arranged in a balanced still life. Recognizable protective gear and cycling accessories.

### bicycle

[PNG](../assets/categories/bicycle-illustration-v1.png)

One complete modern mountain bicycle in side-three-quarter view, warm yellow frame, graphite tires and saddle, silver spokes. Recognizable realistic bicycle geometry, both wheels fully visible.

### fitness

[PNG](../assets/categories/fitness-illustration-v1.png)

Two charcoal dumbbells, a small warm yellow kettlebell and a rolled light-gray exercise mat. A balanced sports training still life, clear silhouettes.

### parts

[PNG](../assets/categories/parts-illustration-v1.png)

A bicycle crankset with a warm yellow crank arm, graphite chainring and silver cassette gear cluster, arranged as a clean balanced still life. These are mechanical bicycle replacement parts.

### scooter

[PNG](../assets/categories/scooter-illustration-v1.png)

One stylish kick scooter with warm yellow deck and graphite upright handlebar, accompanied by a small graphite skateboard with yellow wheels and one charcoal inline skate. A balanced coherent group, the scooter is dominant.

### skiing

[PNG](../assets/categories/skiing-illustration-v1.png)

A pair of warm yellow alpine skis and two graphite ski poles leaning diagonally, with one white and charcoal ice skate in front. Recognizable winter sporting equipment.

### tourism

[PNG](../assets/categories/tourism-illustration-v1.png)

A compact warm yellow camping tent with charcoal doorway, next to a white stand-up paddleboard with yellow stripe and a graphite paddle. A balanced outdoors and watersports still life.

## Проверка публикации

FTP-деплой сравнивает семь PNG, `category-tiles.css`, HTML, JS и CSS с репозиторием побайтно.
Отдельный запуск каждого из Яндекс, Opera, Edge и Safari не заменяется сменой user-agent;
для них поставляется один набор PNG и стандартная адаптивная сетка.

