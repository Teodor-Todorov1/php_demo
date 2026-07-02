# Developer A — Implementation Plan
### Platform, Image I/O, and Color Foundations

> Standalone execution document. You should be able to work from this alone; the shared master plan (`IMPLEMENTATION_PLAN.md`) and the frozen contract reference (`docs/contracts.md`) are backup, not required reading.

---

## 1. Project Context (shared)

We are building **`image-color-analyzer`**, a reusable Composer library that takes a PNG or JPEG (from a file handle, stream, or path), crops the surrounding near-white background, clusters the remaining colors, and returns each principal print color with its coverage percentage as JSON:

```json
[ { "color": "#FF0000", "coverage_percent": 42.5 }, { "color": "#0000FF", "coverage_percent": 31.2 } ]
```

Pipeline: `ImageSource → ImageLoader → Raster → Cropper → Raster → Clusterer → ClusterResult → CoverageCalculator → ColorCoverage[]`.

Stack: PHP ≥ 8.3 (develop on 8.4, CI on 8.3/8.4/8.5), `ext-gd` required, `ext-imagick` optional. Analysis color space is **CIELAB**. Clustering is **k-means++ over a weighted color histogram**. No third-party runtime dependencies.

Three developers work in parallel behind frozen interfaces:
- **You (A)** own the platform: contracts, options, exceptions, image loading, color conversion, test support, CI, and the facade skeleton.
- **B** owns the white-background cropper.
- **C** owns clustering, coverage, examples, and docs assembly.

---

## 2. Mission and Ownership Overview

**Mission:** Be the foundation the whole team builds on. You define the seams (interfaces + DTOs), deliver reliable image decoding and color math, and provide the test scaffolding that lets B and C work before your production code is finished. If you are late or your contracts churn, the parallel plan collapses — so your Week 1 output is the most schedule-critical work on the team.

**You own these directories/files:**

```
src/Contracts/            # ALL interfaces + DTOs (frozen surface)
src/Options/              # CropOptions, ClusterOptions, AnalyzerOptions
src/Exception/            # exception hierarchy
src/ImageLoader/          # GdImageLoader, ImagickImageLoader, FileImageSource, InMemoryRaster
src/Color/                # ColorConverter
src/PublicAPI/            # ImageColorAnalyzer facade + AnalyzerFactory (skeleton; joint final wiring)
tests/Support/            # SyntheticImageFactory + Fakes (FakeImageLoader, PassthroughCropper)
tests/Unit/Contracts/     # DTO tests
tests/Unit/Color/         # ColorConverter tests
tests/Unit/ImageLoader/   # loader / raster / source tests
composer.json, phpunit.xml.dist, phpstan.neon.dist, .php-cs-fixer.dist.php
.github/workflows/ci.yml, CODEOWNERS, .gitignore
```

---

## 3. Goals and Success Criteria

1. **Contracts frozen by Week 1, day 3.** Every interface, DTO, and options object compiles, is documented, and does not change afterward without an ADR + all-three sign-off.
2. **Test scaffolding shipped in Week 1** so B and C are never blocked: `SyntheticImageFactory`, `FakeImageLoader`, `PassthroughCropper`, and a working `InMemoryRaster` + `ColorConverter`.
3. **`GdImageLoader` decodes real PNG and JPEG** from a handle/stream/path into a `Raster`, normalizing palette/grayscale/alpha, with clear exceptions for unsupported/corrupt input.
4. **`ColorConverter` is numerically correct** (sRGB↔Lab↔HSV, ΔE) versus published reference values, and pure.
5. **CI is green across PHP 8.3/8.4/8.5** and enforces PSR-12 + PHPStan level 8.
6. **The facade wiring exists** and composes the four components without knowing their internals.

---

## 4. Detailed Responsibilities

- Author and maintain the **frozen contract surface**: `Raster`, `ColorRGBA`, `BoundingBox`, `CropResult`, `Cluster`, `ClusterResult`, `ColorCoverage`, `ImageFormat`, and the four component interfaces (`ImageLoaderInterface`, `CropperInterface`, `ClustererInterface`, `CoverageCalculatorInterface`), plus `ImageSource`.
- Own all **options objects** (`CropOptions`, `ClusterOptions`, `AnalyzerOptions`) that appear in interface signatures. B and C may request new fields, but changes go through you (contract change → ADR).
- Own the **exception hierarchy** and the rule that every thrown exception implements `ImageAnalyzerException`.
- Implement **image loading** end-to-end: source normalization (path/stream/resource/bytes), format sniffing, GD decoding, and an optional Imagick adapter.
- Implement **color conversions** used by B (white detection) and C (clustering).
- Provide and maintain **test doubles and fixtures factory**.
- Own **project tooling**: composer config, PHPUnit/PHPStan/CS-Fixer config, CI, CODEOWNERS.
- Provide the **facade skeleton** so integration can be exercised against fakes from Week 1; co-lead final wiring.

---

## 5. Technical Design Decisions (your scope)

**GD as default, Imagick optional (ADR-002).** GD is bundled, sufficient for 8-bit PNG/JPEG, and keeps CI/security simple. You expose everything behind `ImageLoaderInterface`; `ImagickImageLoader` is a drop-in for CMYK/ICC/very large images. GD's known weakness — CMYK JPEG — must be detected and either routed to Imagick (if present) or rejected with `UnsupportedImageException`.

**CIELAB is the analysis space (ADR-001).** You provide `rgbToLab` (sRGB → linear → XYZ (D65) → L\*a\*b\*), `rgbToHsv`, and CIE76 `deltaE`. RGB stays the transport format. Keep the converter pure and allocation-light; it is called on every pixel/bin downstream.

**`Raster` is an interface; storage is an implementation detail.** The scaffold's `InMemoryRaster` stores `ColorRGBA` objects, which is fine for tests and small images but **memory-heavy for large photos** (one object per pixel). See Performance below — plan a packed-int or GD-backed raster so a 20–50 MP image does not exhaust memory.

**Alpha model.** GD stores alpha as 0–127 (0 = opaque). Convert to 0–255 with `a = (int) round((127 - $gdAlpha) * 255 / 127)`. `hasAlpha()` is true only when the source actually carries transparency (PNG with alpha), so JPEGs report `false`.

**Options carry defaults; the facade passes them through.** `AnalyzerOptions` composes `CropOptions` and `ClusterOptions` (using `new` in initializers, PHP 8.1+). Never read global config.

---

## 6. Step-by-Step Implementation Tasks (execution order)

1. **Scaffolding & tooling** *(done in the repo scaffold — verify and own it).* `composer.json` (`php>=8.3`, `ext-gd`, dev deps), PSR-4 autoload, `phpunit.xml.dist`, `phpstan.neon.dist` (level 8), `.php-cs-fixer.dist.php` (PSR-12 + strict types), `.github/workflows/ci.yml`, `CODEOWNERS`, `.gitignore`.
2. **Freeze the contracts.** Finalize every interface + DTO + options object in `src/Contracts` and `src/Options`, with full docblocks. Publish `docs/contracts.md`. Announce the freeze to B and C. *(This is your day-2/3 gate.)*
3. **Exceptions.** `ImageAnalyzerException` (interface extends `Throwable`), `InvalidImageException`, `UnsupportedImageException`, and `NotImplementedException` (temporary, removed before v1).
4. **`ColorRGBA` + `BoundingBox`.** Validating constructors, `toHex()`, `toRgbTriplet()`, `isTransparent()`, `area()`.
5. **`InMemoryRaster`.** Array-backed `Raster` with bounds-checked `pixelAt`, generator `pixels()`, and `crop(BoundingBox)`. Ship first (unblocks B and C tests).
6. **`ColorConverter`.** Implement Lab/HSV/ΔE; validate against reference values (Task in §8). Ship early — B needs it for white detection, C for clustering.
7. **Test support.** `SyntheticImageFactory` (`solid`, `contentOnBorder`, `bands`), `FakeImageLoader`, `PassthroughCropper`. Ship with #5/#6.
8. **`FileImageSource` / source normalization.** Accept path and stream; sniff PNG/JPEG by **magic bytes** (`\x89PNG…`, `\xFF\xD8\xFF`), never by extension; rewindable `stream()`. Add a bytes/`ImageSource` path in the facade's `normalizeSource`.
9. **`GdImageLoader::load()`.** Read all bytes → `imagecreatefromstring()` → `imagepalettetotruecolor()` → `imagesavealpha(, true)`; iterate pixels via `imagecolorat()`, split channels, convert alpha; detect true alpha usage for `hasAlpha()`; build `InMemoryRaster` (or the optimized raster). Guard dimensions/memory. Detect CMYK JPEG and route/throw.
10. **Performance pass on the raster** (see §17): switch storage to packed ints or a GD-backed lazy raster if profiling shows memory pressure.
11. **`ImagickImageLoader` (optional).** Same interface; used when `ext-imagick` is present or for CMYK. Feature-detect at runtime.
12. **Facade skeleton + `AnalyzerFactory`.** `ImageColorAnalyzer::analyze()` sequences loader→cropper→clusterer→coverage; `analyzeAsJson()`; `createDefault()` wires the concrete stack. *(Skeleton in Week 1; final wiring joint in Week 3.)*

---

## 7. Internal Milestones and Weekly Timeline

**Week 1 — Foundations gate (highest priority):**
- Day 1: onboarding, confirm tooling, CI skeleton green (empty).
- Day 2–3: **freeze contracts**, publish `docs/contracts.md`, notify team.
- Day 3–5: ship `InMemoryRaster`, `ColorConverter`, `SyntheticImageFactory`, fakes, `FileImageSource`. B and C are now unblocked.
- Exit: M1 — contracts frozen; `composer install` + CI green on all versions; foundation classes tested.

**Week 2 — Core I/O:**
- `GdImageLoader::load()` complete with normalization + error handling.
- `ColorConverter` reference-value tests locked.
- Optional `ImagickImageLoader`.
- Exit: M2 — loader + converter merged, ≥90% unit-tested, PHPStan clean.

**Week 3 — Integration:**
- Co-lead facade wiring (replace fakes with real components).
- Support B/C during end-to-end assembly; add real sample images to fixtures with C.
- Performance pass on raster if needed.
- Exit: M3 — end-to-end analysis works on real images from a file handle.

**Week 4 — Hardening & release:**
- Edge-case hardening (huge/tiny/mono/transparent/CMYK), finalize ADR-001/002, ensure the Imagick CI job is green, tag `v1.0.0`.

---

## 8. Unit Testing Responsibilities

Owned suites: `tests/Unit/Contracts/`, `tests/Unit/Color/`, `tests/Unit/ImageLoader/`.

- **`ColorRGBA`**: `toHex` padding/upper-case, `isTransparent` threshold, out-of-range channel throws.
- **`BoundingBox`**: validation, `area()`.
- **`InMemoryRaster`**: dimensions, `pixelAt`, `crop` returns correct sub-region, out-of-bounds throws, pixel-count mismatch throws.
- **`ColorConverter`** (reference values, delta 0.5): white → L\*≈100/a≈0/b≈0; black → L\*≈0; mid-gray (128) → L\*≈53.6; ΔE(white,black)≈100; ΔE(x,x)=0; HSV primaries → H = 0/120/240.
- **`FileImageSource`**: PNG/JPEG magic-byte detection; unknown format throws `UnsupportedImageException`; unreadable path throws `InvalidImageException`.
- **`GdImageLoader`**: `supports()` true; `load()` on a **generated fixture** (build small images with GD in the test) — correct width/height, known corner pixel, alpha preserved on RGBA PNG, palette PNG normalized to truecolor, grayscale JPEG readable, corrupt bytes throw.

Because your loader tests need images, generate tiny PNG/JPEG fixtures programmatically in the test (via `imagecreatetruecolor` + `imagepng`/`imagejpeg` to `php://temp`) so they are deterministic and don't bloat the repo.

---

## 9. Integration Responsibilities

- **You are the primary integrator.** Own the facade skeleton from Week 1 so B and C can run the whole pipeline against `FakeImageLoader` + `PassthroughCropper` + their real module.
- Co-lead the **Week 3 wiring session** that swaps fakes for real components in `AnalyzerFactory::createDefault()`.
- Ensure the facade's `normalizeSource` accepts `ImageSource`, resource, and path (the assignment requires file handles).
- Keep the fakes in lock-step with any (rare) contract change.

---

## 10. Required Interfaces and Dependencies from Others

**You depend on no one** — this is by design; you unblock the others. You must, however:
- **Consume from B:** nothing at build time; at integration you plug `WhiteBackgroundCropper` into the factory.
- **Consume from C:** nothing at build time; at integration you plug `KMeansClusterer` + `PercentageCoverageCalculator` into the factory.
- **Watch for requests:** B may ask for a `labToRgb` or extra `CropOptions` field; C may ask for `labToRgb` or a distance helper. Treat any signature change as a contract change (ADR). Prefer additive changes.

---

## 11. Expected Deliverables

- Frozen `src/Contracts`, `src/Options`, `src/Exception`.
- Working `InMemoryRaster`, `FileImageSource`, `GdImageLoader`, optional `ImagickImageLoader`, `ColorConverter`.
- `tests/Support` (factory + fakes) and passing unit suites for your modules.
- Facade + factory wiring.
- CI, CODEOWNERS, tooling config.
- ADR-001 (color space) and ADR-002 (GD vs Imagick); `docs/contracts.md`.

---

## 12. Definition of Done (Developer A)

- All contracts/options/exceptions finalized, documented, unchanged since freeze (or changed only via merged ADRs).
- `GdImageLoader` loads PNG + JPEG from path, stream, and resource; normalizes palette/grayscale/alpha; throws typed exceptions on corrupt/unsupported input; `hasAlpha()` correct.
- `ColorConverter` matches reference values within tolerance; pure and side-effect-free; PHPStan level 8 clean.
- `InMemoryRaster` (or optimized raster) passes crop/bounds tests and does not exhaust memory on a 20 MP image.
- Fakes + factory let the pipeline run end-to-end.
- CI green on 8.3/8.4/8.5 and on the Imagick job; PSR-12 enforced.

---

## 13. Risks and Mitigations (your scope)

- **Interface churn breaks the team.** → Freeze early; additive-only changes; ADR + all-three review for any signature change; readonly DTOs.
- **Memory blow-up materializing large images as objects.** → Packed-int or GD-backed raster; validate/reject absurd dimensions; document limits. (See §17.)
- **GD can't handle CMYK/16-bit/ICC.** → Detect colorspace; route to Imagick or throw `UnsupportedImageException` with guidance.
- **Alpha handling bugs (GD 0–127 vs 0–255).** → Centralize the conversion; unit-test an RGBA PNG round-trip.
- **You become the integration bottleneck.** → Ship facade skeleton + fakes in Week 1 so others self-serve.
- **`ColorConverter` subtle math errors.** → Reference-value tests; consider mutation testing on the converter.

---

## 14. Code Review Responsibilities

- **You review effectively every PR.** Per `CODEOWNERS` you are a required reviewer on B's cropper and C's clustering/coverage (contract-adherence gate), plus all contract/options/loader/color changes.
- Enforce: no direct edits to frozen contracts without an ADR; PSR-12; PHPStan clean; tests added; conventional-commit titles.
- Your own PRs (contracts especially) require **all three** approvals when they touch `src/Contracts` or `src/Options`.

---

## 15. Git Workflow Expectations

- Branch off `main`, short-lived: `feat/loader-color-foundation`, `feat/contracts-freeze`, `chore/ci-matrix`, etc.
- **Conventional Commits**: `feat(loader): decode PNG/JPEG via GD`, `feat(contracts): freeze v1 interfaces`, `test(color): reference-value ΔE checks`.
- Small, single-purpose PRs; CI green before review; **squash-merge**; no self-merge.
- Protect `main` (require PR + green CI + 1 approval; contracts require 3).

---

## 16. Documentation Responsibilities

- `docs/contracts.md` — the authoritative frozen-interface reference (keep in sync if an ADR changes anything).
- `docs/ADR-001-color-space.md`, `docs/ADR-002-gd-vs-imagick.md`.
- Inline docblocks on every public method (types, throws, units).
- A short "Extending with a custom loader" note in the README (coordinate with C who assembles it).

---

## 17. Performance Considerations

- **Raster memory is your biggest risk.** `list<ColorRGBA>` is ~tens of bytes/pixel; a 24 MP image → hundreds of MB. Prefer storing a packed `int` per pixel (`(a<<24)|(r<<16)|(g<<8)|b`) and constructing `ColorRGBA` lazily in `pixelAt()`/`pixels()`, **or** a `GdRaster` that keeps the GD handle and reads on demand. Keep the `Raster` interface unchanged.
- **Decode cost.** Per-pixel `imagecolorat()` is O(W·H); acceptable, but avoid re-scanning. Provide `pixels()` as a generator to stream without a second copy.
- **Guard rails.** Reject images beyond a configurable max dimension/pixel budget with a clear exception; optionally expose a downscale hook (coverage is proportional, so downscaling before analysis is legitimate).
- Keep `ColorConverter` branch-light; it's on the hot path for B and C.

---

## 18. Architectural Constraints

- Pure library: no output, no `exit`, no globals, no filesystem assumptions beyond the provided source.
- Every failure path throws an `ImageAnalyzerException` subtype.
- Contracts are the only coupling point; never leak GD/Imagick types across an interface.
- Deterministic behavior (no randomness in your modules).

---

## 19. Task Checklist

- [ ] Verify/own scaffolding + tooling; CI skeleton green on 8.3/8.4/8.5.
- [ ] Finalize & **freeze** contracts, options, exceptions; publish `docs/contracts.md`.
- [ ] Implement `ColorRGBA`, `BoundingBox` (+ tests).
- [ ] Implement `InMemoryRaster` (+ tests); ship to team.
- [ ] Implement `ColorConverter` (+ reference-value tests); ship to team.
- [ ] Implement `SyntheticImageFactory`, `FakeImageLoader`, `PassthroughCropper`; ship to team.
- [ ] Implement `FileImageSource` + magic-byte sniffing (+ tests).
- [ ] Implement `GdImageLoader::load()` with normalization + error handling (+ fixture tests).
- [ ] Performance pass on raster storage; add dimension guard.
- [ ] Optional `ImagickImageLoader` + CI Imagick job green.
- [ ] Facade skeleton + `AnalyzerFactory`; co-lead final wiring.
- [ ] ADR-001, ADR-002; docblocks; README loader note.

## 20. Week-by-Week Roadmap

| Week | Focus | Exit criterion |
|------|-------|----------------|
| 1 | Freeze contracts; ship raster/converter/factory/fakes | M1: contracts frozen, foundation shipped, CI green |
| 2 | `GdImageLoader`, converter tests, optional Imagick | M2: loader+converter merged, ≥90% covered |
| 3 | Facade wiring, integration support, real fixtures | M3: end-to-end from a file handle |
| 4 | Hardening, ADRs, Imagick job, release | M4: `v1.0.0` tagged |

## 21. Final Acceptance Checklist (Developer A)

- [ ] Contracts frozen and stable since Week 1 (all changes via ADR).
- [ ] PNG + JPEG load from path, stream, and handle; palette/grayscale/alpha normalized.
- [ ] Corrupt/unsupported inputs throw typed exceptions; CMYK routed/rejected cleanly.
- [ ] `ColorConverter` verified against reference values; pure.
- [ ] No memory blow-up on a 20 MP image.
- [ ] Fakes + factory enable full-pipeline runs.
- [ ] CI green on 8.3/8.4/8.5 + Imagick; PHPStan L8; PSR-12.
- [ ] ADR-001, ADR-002, `docs/contracts.md` complete and current.
