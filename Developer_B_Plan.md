# Developer B — Implementation Plan
### White Background Cropper

> Standalone execution document. You should be able to work from this alone; the shared master plan (`IMPLEMENTATION_PLAN.md`) and the frozen contract reference (`docs/contracts.md`) are backup, not required reading.

---

## 1. Project Context (shared)

We are building **`image-color-analyzer`**, a reusable Composer library that takes a PNG or JPEG (from a file handle, stream, or path), crops the surrounding near-white background, clusters the remaining colors, and returns each principal print color with its coverage percentage as JSON:

```json
[ { "color": "#FF0000", "coverage_percent": 42.5 }, { "color": "#0000FF", "coverage_percent": 31.2 } ]
```

Pipeline: `ImageSource → ImageLoader → Raster → **Cropper** → Raster → Clusterer → ClusterResult → CoverageCalculator → ColorCoverage[]`. **Your module is the second stage.**

Stack: PHP ≥ 8.3 (develop on 8.4, CI on 8.3/8.4/8.5), `ext-gd` required. Analysis color space is **CIELAB**. No third-party runtime dependencies.

Three developers work in parallel behind frozen interfaces:
- **A** owns the platform: contracts, options, exceptions, image loading, `ColorConverter`, test support.
- **You (B)** own the white-background cropper.
- **C** owns clustering, coverage, examples, and docs.

---

## 2. Mission and Ownership Overview

**Mission:** Remove the near-white margin around the image content so downstream color analysis sees only the artwork — without ever deleting legitimate content, including white *inside* the artwork. You turn a `Raster` into a tightly-cropped `Raster` plus the bounding box you found.

**You own:**

```
src/WhiteBackgroundCropper/
    WhiteBackgroundCropper.php        # implements CropperInterface
tests/Unit/WhiteBackgroundCropper/    # your unit suite
tests/Fixtures/generated/             # cropping fixtures you generate (shared dir; your images)
```

You also drive the **fields of `CropOptions`** (owned physically by A in `src/Options/`, but you specify what tuning knobs it needs). Any field change is a contract change → ADR + A/C review.

---

## 3. Goals and Success Criteria

1. **Correct crop on symmetric and asymmetric white borders** — the returned box reaches actual colored content on all four sides.
2. **Never crop interior content.** White regions surrounded by artwork are preserved (guaranteed by border-inward scanning).
3. **Robust near-white detection** — handles anti-aliasing, JPEG compression halos, and off-white scans via a configurable Lab tolerance.
4. **Sensible behavior on degenerate inputs** — fully white, no-margin, single-pixel content, fully transparent.
5. **Noise-resistant** — a few stray pixels in the margin do not defeat cropping (line-fraction guard), yet genuine small content is never erased (raw-extent fallback).
6. **Deterministic and fast** — single O(W·H) pass, no randomness.

---

## 4. Detailed Responsibilities

- Implement `WhiteBackgroundCropper::crop(Raster $image, CropOptions $options): CropResult`.
- Define and tune the **near-white predicate** using A's `ColorConverter` (Lab) with an RGB fast-path.
- Implement the **border-inward scan** that computes the minimal content bounding box.
- Handle **transparency** as background (alpha below `alphaThreshold`).
- Return a `CropResult` with the cropped `Raster`, the `BoundingBox`, and a `wasCropped` flag.
- Own your unit tests and cropping fixtures.
- Keep the module decoupled: you consume `Raster` and produce `Raster`; you never call the loader, clusterer, or coverage code.

---

## 5. Technical Design Decisions (your scope)

**Border-inward scanning, not global white removal.** You only trim from the four edges toward the center. This structurally guarantees interior white is never removed — a core acceptance criterion. Do **not** implement "delete all white pixels."

**Near-white judged in CIELAB.** A pixel is background if it is transparent (`alpha < alphaThreshold`) **or** near-white: `L* >= lightnessMin` **and** chroma `sqrt(a*^2 + b*^2) <= chromaMax`. Defaults: `lightnessMin = 95.0`, `chromaMax = 5.0`. Lab makes "near-white" perceptually meaningful and robust to slight hue casts from scanning.

**RGB fast-path + memoization for speed.** Most margin pixels are identical (pure white). Compute a cheap RGB pre-check first (e.g., all channels ≥ ~245) to accept obvious whites without Lab; only borderline pixels pay for `rgbToLab`. Memoize the background decision by packed RGB int (`(r<<16)|(g<<8)|b`) in a small map so repeated background colors are evaluated once.

**Line-fraction noise guard with a raw-extent fallback.** A scan line (row/column) is treated as "content" only if its fraction of content pixels ≥ `lineContentFraction` (default `0.002`). This ignores dust/specks. **But** if the guard would eliminate *all* rows/columns while genuine content pixels exist, fall back to the raw min/max content extent so you never erase small-but-real artwork.

**One-pass counting.** Iterate every pixel once, accumulating `rowContentCount[y]` and `colContentCount[x]`. Then derive the four edges from these arrays. O(W·H) time, O(W+H) memory, single pass.

---

## 6. Step-by-Step Implementation Tasks (execution order)

1. **Skeleton & DI.** Constructor takes `ColorConverter` (injected). Confirm the `CropperInterface` signature and `CropResult`/`BoundingBox` shapes from `docs/contracts.md`.
2. **`isBackground(ColorRGBA $p): bool`.** Transparent → true. RGB fast-path for obvious white → true. Else compute Lab via `ColorConverter`, apply `L*`/chroma thresholds. Add the packed-int memoization map.
3. **One-pass content counting.** Build `rowContentCount` and `colContentCount` by scanning all pixels once; also track raw `minX/minY/maxX/maxY` of content pixels and a `hasAnyContent` flag.
4. **Edge derivation with guard.** `top` = first `y` where `rowContentCount[y] >= lineContentFraction * width`; `bottom` = last such `y`; `left`/`right` analogously over columns with `* height`. If the guard yields an empty range but `hasAnyContent`, use the raw extent instead.
5. **Assemble result.** If `!hasAnyContent` → return original raster, full-image `BoundingBox`, `wasCropped = false`. If the computed box equals the full image → `wasCropped = false`. Otherwise `crop()` the raster to the box and return `wasCropped = true`.
6. **Edge cases** (see §17-Edge). Add each as you go, backed by a test.
7. **Performance pass.** Verify the RGB fast-path + memoization keep large-image cropping fast; ensure only one full scan.
8. **Docs.** Inline docblocks; a README subsection (hand to C for assembly) describing tolerance tuning.

---

## 7. Internal Milestones and Weekly Timeline

**Week 1 — Design & harness:**
- Read frozen contracts; confirm `CropOptions` fields (request changes now if needed).
- Build cropping fixtures with A's `SyntheticImageFactory` (`contentOnBorder`, plus custom noisy/near-white variants).
- Write the module skeleton against the interface + a red test suite.
- Exit: skeleton compiles; fixtures ready; tests failing/incomplete as expected.

**Week 2 — Implementation:**
- Implement `isBackground`, one-pass counting, edge derivation, guard + fallback, assembly.
- Cover all edge cases with tests; get the suite green.
- Exit: M2 — `WhiteBackgroundCropper` merged, ≥90% covered, PHPStan clean.

**Week 3 — Integration & hardening:**
- Integrate into the facade (your cropped `Raster` feeds C's clusterer).
- Test against real scanned/photographed samples with A and C; tune tolerances.
- Exit: M3 — correct cropping within the end-to-end pipeline on real images.

**Week 4 — Polish:**
- Additional edge hardening, tolerance documentation, final review.
- Exit: M4 — acceptance criteria met, docs done.

---

## 8. Unit Testing Responsibilities

Suite: `tests/Unit/WhiteBackgroundCropper/`. Use A's `SyntheticImageFactory` for exact ground truth.

- **Symmetric border:** `contentOnBorder(100,100,20,red)` → box `(20,20,60,60)`, `wasCropped = true`.
- **Asymmetric margins:** different top/bottom/left/right margins → exact box.
- **Interior white preserved:** red block containing a white sub-rectangle → interior white NOT trimmed.
- **Near-white tolerance:** border filled `(250,250,250)` and `(248,249,250)` → still cropped; a genuinely light-gray content `(200,200,200)` → kept.
- **All-white / all-transparent:** `wasCropped = false`, original returned.
- **No margin:** content touches all edges → `wasCropped = false`.
- **Single-pixel / thin-line content:** raw-extent fallback keeps it (not erased by the fraction guard).
- **Noise guard:** scatter a handful of stray dark pixels in the margin → ignored; box matches the real content.
- **Transparent margin (PNG):** alpha-0 border treated as background and cropped.
- **Property:** cropped width ≤ original width and cropped height ≤ original height, always.

Testing strategy: assert on the returned `BoundingBox` (deterministic and exact) rather than re-reading pixels; add one pixel-level check to confirm the cropped raster's corner equals the expected content color.

---

## 9. Integration Responsibilities

- Your output `Raster` is C's clusterer input — confirm with C that a `Raster` (not `CropResult`) is what flows in the facade (the facade unwraps `->raster`).
- Participate in the Week 3 wiring session; verify the cropper behaves correctly on A's real `GdImageLoader` output (true alpha, real anti-aliasing), not just synthetic rasters.
- Provide a couple of real bordered sample images to `tests/Fixtures/real/` (coordinate with C who curates them).

---

## 10. Required Interfaces and Dependencies from Others

**Consume from A (must exist before you finish, available Week 1 as frozen contracts + shipped foundation):**
- `Raster`, `ColorRGBA`, `BoundingBox`, `CropResult` (contracts).
- `CropperInterface` (implement it).
- `CropOptions` (`lightnessMin`, `chromaMax`, `lineContentFraction`, `alphaThreshold`).
- `Color\ColorConverter` (`rgbToLab`) — for near-white detection.
- `InMemoryRaster` + `SyntheticImageFactory` — for tests.

**You are unblocked from day 1** because these are frozen interfaces; use `SyntheticImageFactory` and, if A's `ColorConverter` lands slightly later, a temporary RGB-only predicate, then switch to Lab.

**Consume from C:** nothing directly.

**You must deliver to unblock others:** the cropper is needed for **M3 integration**; it is not on C's Week-2 critical path (C tests clustering with the `PassthroughCropper` fake), so aim to be integration-ready by early Week 3.

---

## 11. Expected Deliverables

- `WhiteBackgroundCropper` implementing `CropperInterface`, fully tested.
- A comprehensive cropping unit suite + fixtures.
- Tolerance-tuning documentation (README subsection) and inline docblocks.
- A couple of real bordered sample images for integration tests.

---

## 12. Definition of Done (Developer B)

- Returns the smallest rectangle containing all non-white, non-transparent content.
- Near-white tolerance configurable and effective on anti-aliased/scanned borders.
- Interior white never removed; genuine small content never erased.
- Handles all-white, no-margin, single-pixel, transparent-margin, and noisy-margin cases.
- Single O(W·H) pass; deterministic; PHPStan level 8 clean; PSR-12.
- Exact-box tests on synthetic fixtures pass; behaves correctly on real `GdImageLoader` output.

---

## 13. Risks and Mitigations (your scope)

- **Cropping legitimate white content.** → Border-inward scan only; never global white removal; explicit "interior white" test.
- **Anti-aliasing/compression halos misjudged.** → Lab tolerance with tunable `lightnessMin`/`chromaMax`; test near-white variants.
- **Noise defeats cropping (over- or under-cropping).** → Line-fraction guard against specks + raw-extent fallback so small real content survives.
- **White-on-white artwork.** → Inherent ambiguity; document that near-white content within tolerance will be trimmed; expose tolerance to the caller.
- **Performance on large images.** → RGB fast-path + memoization; single pass; avoid per-pixel Lab where possible.
- **`CropOptions` needs a new knob mid-project.** → Raise early (Week 1) so A can freeze it; later changes need an ADR.

---

## 14. Code Review Responsibilities

- Per `CODEOWNERS`, you are a **required reviewer on C's** `ColorClusterer` and `CoverageCalculator` PRs — check contract adherence and that transparency handling is consistent with your cropper.
- Your own PRs are reviewed by **A** (contract adherence) and **C**.
- Enforce: PSR-12, PHPStan clean, tests added, conventional-commit titles, no self-merge.

---

## 15. Git Workflow Expectations

- Branch: `feat/white-background-cropper` (and small follow-ups like `test/cropper-edge-cases`).
- **Conventional Commits**: `feat(cropper): border-inward near-white scan`, `test(cropper): interior-white preservation`, `perf(cropper): memoize background predicate`.
- Small PRs; CI green before review; **squash-merge**; no self-merge; rebase on `main` before opening a PR.

---

## 16. Documentation Responsibilities

- Inline docblocks on `crop()` and the predicate, stating units (Lab `L*`, chroma) and the guard/fallback logic.
- A README subsection (delivered to C for assembly) explaining how to tune `CropOptions` for scans vs clean exports, with before/after guidance.
- If you change `CropOptions`, update `docs/contracts.md` via the ADR.

---

## 17. Performance Considerations & Edge Cases

**Performance**
- One full O(W·H) pass; never re-scan.
- RGB fast-path avoids Lab for the overwhelming majority of margin pixels.
- Memoize the background decision by packed RGB int; margins are highly repetitive, so hit-rate is very high.
- Prefer counting into `rowContentCount`/`colContentCount` arrays over four separate directional scans.

**Edge cases (each must have a test)**
- Fully white or fully transparent → `wasCropped = false`, original returned.
- No margin (content at every edge) → full-image box, `wasCropped = false`.
- Single-pixel or 1px-line content → raw-extent fallback preserves it.
- Off-center content / asymmetric margins → per-edge detection.
- Anti-aliased / near-white borders → tolerance handles; verify a `(250,250,250)` border crops.
- Stray noise pixels in the margin → line-fraction guard ignores them.
- Transparent (alpha) borders → treated as background.
- Interior white region → preserved.
- Very large image → still one pass, memoized predicate.

---

## 18. Architectural Constraints

- Consume `Raster`, produce `CropResult`; never touch loader/clusterer/coverage internals.
- No output, no `exit`, no globals; deterministic (no randomness).
- All tuning via `CropOptions`; no magic constants beyond documented defaults.
- Never leak GD/Imagick types; work purely through the `Raster` interface.

---

## 19. Task Checklist

- [ ] Confirm frozen `CropperInterface`, `CropResult`, `BoundingBox`, `CropOptions`; request any option field now.
- [ ] Build cropping fixtures (symmetric, asymmetric, near-white, noisy, transparent, interior-white).
- [ ] Implement `isBackground()` (transparent + RGB fast-path + Lab thresholds + memoization).
- [ ] Implement one-pass row/column content counting + raw extent tracking.
- [ ] Implement edge derivation with line-fraction guard + raw-extent fallback.
- [ ] Assemble `CropResult`; set `wasCropped` correctly.
- [ ] Cover every edge case with a test; get suite green.
- [ ] Performance pass (single scan, memoization verified).
- [ ] Integrate into facade; validate on real `GdImageLoader` output.
- [ ] Docblocks + README tuning subsection; provide real sample images.

## 20. Week-by-Week Roadmap

| Week | Focus | Exit criterion |
|------|-------|----------------|
| 1 | Design, fixtures, skeleton against frozen contracts | Skeleton compiles; fixtures ready |
| 2 | Full implementation + all edge-case tests | M2: cropper merged, ≥90% covered |
| 3 | Facade integration; tune on real images | M3: correct cropping end-to-end |
| 4 | Hardening + tolerance docs | M4: acceptance met |

## 21. Final Acceptance Checklist (Developer B)

- [ ] Smallest content rectangle returned on all four sides.
- [ ] Interior white preserved; small real content never erased.
- [ ] Near-white/anti-aliased/scanned borders cropped within configurable tolerance.
- [ ] All-white, no-margin, single-pixel, transparent, and noisy-margin cases handled.
- [ ] Single O(W·H) pass; deterministic; PHPStan L8; PSR-12.
- [ ] Correct behavior on real `GdImageLoader` output in the full pipeline.
- [ ] Tests green; docblocks + tuning docs complete.
