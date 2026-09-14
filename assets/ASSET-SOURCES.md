## Reference assets

Bicycle illustration: https://assets.velostrana.ru/assets/images/bike-schema@2x.png (the illustration in the user-provided reference, https://www.velostrana.ru/velomasterskaya/). Used as an educational diagram, not as a product photograph.

Brand PNG logos copied from the existing ProfiSport storefront https://velo56.ru/ (brands carousel). Names were visually checked against the source logos.
TechTeam SVG: https://techteam.ru/ (desktop header).
Maxiscoo SVG: https://maxiscoo.ru/ (svg-logo symbol).
Starfit PNG: https://starfit.ru/wp-content/uploads/2021/12/cropped-Starfit_logo_Blue-300x68.png

MAXXIS PNG: https://www.revedevelo.com/img/cms/Maxxis-Logo.png (cycling retailer's brand artwork).
VINCA SPORT JPG: https://www.velo-shop.ru/upload/resize_cache/iblock/089/270_175_140cd750bba9870f18aada2478b24840a/089cfd2517803e5a134552bf06e30b3a.jpg (https://www.velo-shop.ru/brands/vinca-sport/).
NORDSKI PNG: https://images.seeklogo.com/logo-png/55/1/nordski-logo-png_seeklogo-551169.png (https://seeklogo.com/vector-logo/551169/nordski).

Brand tile accents are sampled/selected to accompany these marks, not a claim of compliance with complete brand guidelines. The showcase selects up to 12 brands present in the catalog with prepared local logos. Unsupported marks and failed logo images are omitted. CSS crops only blank margins around the MAXXIS and NORDSKI artwork without altering the source files.

Category pictograms: custom PNG sprite atlas generated for ProfiSport with imagegen, 2026-09-13. Six cells in a 3-by-2 grid: bicycle, scooter, skiing, parts, fitness, tourism. Transparent background, charcoal and warm yellow; no product photography.

Category atlas v2 corrects the bicycle cell overflow. All six pictograms stay within their 512-by-512 cells; an opaque white background matches the white cards. Generated with imagegen from the original category atlas, 2026-09-13.


## Classic / clean hero — 2026-09-14

- `hero/classic-mountains-v1.webp`: created with the built-in imagegen tool for the user's first, classic/clean reference (`IMG_5361.jpeg`). Photorealistic blue mountain and lake landscape, black/yellow mountain bike on the right, pale space on the left, no text, people, or logos. Heading and CTA are accessible HTML.
- `brand/profisport-logo-classic.svg`: existing outlined ProfiSport logo recolored from white to charcoal, preserving the yellow accent, with the viewBox tightened for the white header.

The production host returns an optimized WebP derivative of `hero/classic-mountains-v1.webp`: 235396 bytes, still 2048×768. The deployed banner was visually inspected on the live storefront. Its SHA-256 is `5e269b2a1f736cf59e052cb3ded78f1baa5681c71053b0ca2f6f12e2f66b7074`; the source SHA-256 is `685003eebda6dbdcb64ac4e4f7bcb707af4395f2f8a51e7bf892c1dfc689c4c2`. Deployment accepts exactly one of these two files for this hero path; other files retain byte-for-byte verification.


## New winter and workshop hero backgrounds — 2026-09-14

Created with built-in imagegen for this site, 2048×768, then encoded as quality-90 JPEG for delivery without resizing. No text is embedded; the headings and buttons remain HTML.
- `hero/classic-snowboard-v1.jpg`: alpine snowboard jump, black/yellow outfit, complete subject on the right, open pale blue sky on the left.
- `hero/classic-workshop-v1.jpg`: illustrative fictional bright workshop with black/yellow bicycle on a stand and mechanic on the right, pale blue-gray wall on the left. It is not a photograph of the actual shop.
