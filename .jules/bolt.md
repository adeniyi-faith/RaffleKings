## 2024-05-18 - Lazy Loading Anti-Pattern
**Learning:** Applying `loading="lazy"` to above-the-fold images is a performance anti-pattern. It can delay the Largest Contentful Paint (LCP) because the browser waits until the layout is calculated to determine if the image is in the viewport before it starts downloading it.
**Action:** Only apply `loading="lazy"` to images that are naturally positioned below the fold (like the lists of gadgets, scholarships, and tutorials).
