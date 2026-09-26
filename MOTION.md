# ProfiSport motion

The shared layer is `motion.css` (tokens, reveals and interactions) and `motion.js`
(one IntersectionObserver for marketing sections). No dependencies or build step.
The stylesheet is linked after page styles. Public pages use `motion-page`; admin
pages additionally use `motion-admin` and opt out of cross-document transitions.
Admin pages do not load the observer script.

## Sport Energy visual layer

`sport-energy.css` carries the public storefront direction from the approved
concept: graphite surfaces, neon-lime actions, condensed italic headings,
diagonal speed marks and stronger image-led cards. The visible brand remains
the Russian `ПрофиСпорт` wordmark already used by the store; the stylesheet
switches its existing SVG to the white variant on the dark header.

The homepage uses the existing local cyclist, workshop, winter and storage
imagery. It adds a compact energy ribbon and four proof points, gives categories
an editorial grid, and restyles the catalog, picker, service and map sections.
Product detail, checkout, profile and the shop/service/workshop/buyer hubs share
the same palette and controls. No remote font, image, library or runtime request
was added.

## Behavior

- Tokens: 140/200/360/480 ms; cubic-bezier(.2,.7,.2,1); 14 px distance,
  3 px hover lift, 1.025 image scale, .98 button press, 45 ms stagger.
  Mobile reveal distance is 8 px with a 280 ms duration and 30 ms stagger.
- Use `class="fade-up"`, `fade-in`, `scale-in` or `slide-in` to register a
  section for one-shot reveal. `data-motion` uses fade-up by default.
  Add `stagger` to a parent of reveal targets; delays are capped at three steps.
- Categories, picker, service copy, store address card and footer sections are
  discovered explicitly. Shop/workshop/buyer hub cards also use the same observer.
  No missing homepage blocks were invented or added as part of motion work.
- First-viewport content is never hidden by the observer. The hero copy gets a
  small translate on slide activation, while the storage scene gets a single
  gradient fade. There are no perpetual effects, particles, WebGL, scroll
  handlers, MutationObservers or blanket will-change layers.
- Product cards use a quick opacity entrance on at most the first eight cards
  in a rendered page. Hover lift/image scale are mouse-only. Existing loading
  placeholders get a static gradient. No extra images or network fetches.
- Buttons have press/focus states, navigation links have active underlines,
  dialogs/cookie notices fade in, and admin status/tab panels use a short fade.
- Native cross-document View Transitions are progressive enhancement. Ordinary
  links, query strings, hashes and history remain unchanged. Secondary public
  pages have a CSS entrance fallback; the homepage does not fade its LCP image.

## Reduced motion and resilience

`prefers-reduced-motion: reduce` disables animations, transitions, button scaling,
smooth scrolling and cross-document transitions. It also reveals every pending
section. The script responds to a preference change during the session and
disconnects its observer. The existing hero autoplay already respects this setting.
Without JS or IntersectionObserver, content is visible. Focus, hash destinations,
printing and BFCache restoration do not leave content hidden.

## Validation

- 32 Node regression scripts pass, including the new behavioral test covering
  first paint, one-shot reveal, missing/failing observer, live reduced motion,
  anchors, keyboard focus and BFCache. Syntax and whitespace checks pass.
- Mobile regression expectations now include the intentional fifth stylesheet.
- Browser preview: homepage and product detail at 390 px, plus both pages at
  1440 px; no horizontal overflow in measured views. The Russian white logo,
  hero, proof strip, category, catalog and product purchase panel were inspected.
- A local fixture API supplied 1,000 products: 24 cards per page, URL page 2
  begins at product 25. No fixture files or synthetic products are deployed.
- Reduced motion behavior is covered by the behavioral regression test,
  including a live preference change. Normal-motion layouts were visually
  inspected in the browser.
- Existing tests cover catalog loading, taxonomy, product loading, mobile layout,
  hero slides, cookie consent, customer/admin surfaces and loyalty integration.

## Limits

No Lighthouse score or before/after Core Web Vitals claim. The three new shared
motion/theme files total about 33 KB before compression and reuse existing local
images; performance still needs measurement on a deployed preview/device.
PHP/MySQL and authenticated admin/bonus transactions are not available locally;
the repository's fresh/legacy CI suite is the server verification gate. Map
embedding/external map availability and a full click-through checkout were not
certified by this local preview. No live deployment or production data changes.
