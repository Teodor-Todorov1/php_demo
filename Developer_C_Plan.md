# Developer C — Implementation Plan
### Color Clustering, Coverage, Results, Examples & Docs

> Standalone execution document. You should be able to work from this alone; the shared master plan (`IMPLEMENTATION_PLAN.md`) and the frozen contract reference (`docs/contracts.md`) are backup, not required reading.

---

## 1. Project Context (shared)

We are building **`image-color-analyzer`**, a reusable Composer library that takes a PNG or JPEG (from a file handle, stream, or path), crops the surrounding near-white background, clusters the remaining colors, and returns each principal print color with its coverage percentage as JSON:

```json
[ { "color": "#FF0000", "coverage_percent": 42.5 }, { "color": "#0000FF", "coverage_percent": 31.2 } ]
```

Pipeline: `ImageSource → ImageLoader → Raster → Cropper → Raster → **Clusterer → ClusterResult → CoverageCalculator → ColorCoverage[]**`. **You own the last two stages — the actual answer the library produces.**

Stack: PHP ≥ 8.3 (develop on 8.4, CI on 8.3/8.4/8.5), `ext-gd` required. Analysis color space is **CIELAB**. Clustering is **k-means++ over a weighted color histogram**, with automatic `k` selection. No third-party runtime dependencies.

Three developers work in parallel behind frozen interfaces:
- **A** owns the platform: contracts, options, exceptions, image loading, `ColorConverter`, test support.
- **B** owns the white-background cropper.
- **You (C)** own clustering, coverage, the public examples, and the README/ADR-003 assembly.

---

## 2. Mission and Ownership Overview

**Mission:** Turn a cropped `Raster` into a small set of representative print colors with accurate coverage percentages that sum to ~100. You are on the **critical path for M3** — nothing produces a real result until your modules work — so treat determinism and correctness as first-class.

**You own:**

```
src/ColorClusterer/
    ColorHistogram.php          # weighted binning, transparency skip
    KMeansClusterer.php         # implements ClustererInterface (k-means++ in Lab)
    KSelector.php               # automatic k (silhouette + elbow diagnostics)
src/CoverageCalculator/
    PercentageCoverageCalculator.php   # implements CoverageCalculatorInterface
examples/
    analyze_from_path.php
    analyze_from_handle.php
tests/Unit/ColorClusterer/
tests/Unit/CoverageCalculator/
docs/ADR-003-clustering.md
README.md                       # final assembly (sections from A and B fold in)
```

---

## 3. Goals and Success Criteria

1. **Similar colors are grouped**, not treated per-pixel — output is a handful of principal colors, not thousands.
2. **Coverage percentages are accurate and sum to exactly 100.0** (largest-remainder rounding).
3. **Transparent pixels are ignored** in both numerator and denominator.
4. **Resolution independence** — a 500 px thumbnail and a 20 MP scan of the same artwork yield comparable results in comparable time (histogram binning).
5. **Determinism** — identical input + seed → identical centroids, weights, and ordering.
6. **Automatic `k`** picks a defensible cluster count; a fixed `k` is honored when provided.
7. **Clean, copy-pasteable examples and README** that match the assignment's output shape.

---

## 4. Detailed Responsibilities

- Implement `ColorHistogram::build()` — quantize pixels to weighted bins, skipping transparent pixels, returning representative colors + weights + total.
- Implement `KMeansClusterer::cluster()` — k-means++ (seeded) in CIELAB over the weighted histogram, with automatic or fixed `k`, plus a merge pass.
- Implement `KSelector::select()` — choose `k` via silhouette (primary) with elbow/WCSS diagnostics.
- Implement `PercentageCoverageCalculator::calculate()` — weights → percentages, largest-remainder normalization to 100.0, sorted descending, mapped to `ColorCoverage`.
- Author the **examples**, the final **README**, and **ADR-003**.
- Own your unit tests and lead the **end-to-end integration test**.

---

## 5. Technical Design Decisions (your scope)

**Bin first, cluster second (ADR-003).** Never run k-means on raw pixels. Quantize each channel to `histogramBitsPerChannel` (default 5 → 32 levels/channel) and accumulate per-bin `sumR, sumG, sumB, count`. The bin's representative color is the **weighted average** `round(sum/count)`, not the bin center — this removes quantization bias. Clustering then runs on unique bins weighted by count, so cost depends on color diversity, not pixel count. This is the single most important performance decision in the library.

**Cluster in CIELAB with k-means++.** Convert each bin's representative RGB to Lab once via A's `ColorConverter`. Seed centroids with **k-means++** (first centroid chosen weighted-random by bin weight; each subsequent centroid chosen with probability ∝ `weight · D²`, where `D` is the min ΔE to already-chosen centroids). Run Lloyd iterations: assign each bin to the nearest centroid by ΔE, recompute each centroid as the **weight-weighted mean in Lab**, repeat until assignments stabilize or `maxIterations` is hit.

**Seeded, deterministic RNG.** Use `mt_srand($options->seed)` and `mt_rand`/`mt_getrandmax` for k-means++ sampling. Break assignment ties by lowest centroid index. Same seed ⇒ identical output.

**Centroid → output RGB without Lab inversion.** For each final cluster, compute the output color as the **weight-weighted average of member bins' representative RGB** (guaranteed in-gamut, real, and cheap). This avoids needing a `labToRgb` from A. If exact Lab-inverse colors are ever required, request `labToRgb` from A (contract change → ADR) — but prefer the averaging approach.

**Merge pass.** After clustering, merge any two clusters whose centroids are within `mergeDeltaE` (default 3.0) and fold any cluster below `minClusterCoverage` (default 0.01 = 1%) into its nearest neighbor. This stops anti-aliasing halos and JPEG fringes from appearing as "principal" colors.

**Automatic `k` via silhouette, bounded.** For `k = 2..min(kMax, uniqueBins)`, cluster and score with a **weighted silhouette**; pick the best. Compute WCSS (elbow) alongside for diagnostics/logging. Degenerate cases: 1 unique color → `k = 1`; `uniqueBins ≤ 2` → `k = uniqueBins`.

**Coverage & rounding.** `percent_i = weight_i / totalAnalyzedPixels · 100`, rounded to 1 decimal, then **largest-remainder** adjustment so the displayed values sum to exactly 100.0. Sort descending; ties broken by hex ascending for stability.

---

## 6. Step-by-Step Implementation Tasks (execution order)

1. **`ColorHistogram::build(Raster, bits, alphaThreshold)`.** Iterate `pixels()`; skip `isTransparent(alphaThreshold)`; compute bin key from quantized channels; accumulate `sumR/sumG/sumB/count`; increment `total`. Return `{colors: list<[r,g,b]>, weights: list<int>, total: int}` where each color is `round(sum/count)`.
2. **Lab projection.** Map representative RGB → Lab via `ColorConverter` once; keep parallel arrays `labPoints`, `weights`, and `rgbPoints`.
3. **`KSelector::select(labPoints, weights, kMax)`.** Implement weighted k-means as a private helper (shared with the clusterer or duplicated minimally), silhouette scoring, and the bounded search. Return the chosen `k`.
4. **`KMeansClusterer::cluster(Raster, ClusterOptions)`.** Build histogram → Lab projection → choose `k` (`fixedK` or `KSelector`) → k-means++ init (seeded) → Lloyd iterations → merge pass → build `list<Cluster>` (each `Cluster` = centroid `ColorRGBA` from weighted-average RGB, `lab` triplet, integer `weight`) → return `ClusterResult(clusters, totalAnalyzedPixels)`.
5. **`PercentageCoverageCalculator::calculate(ClusterResult)`.** Compute percentages, largest-remainder normalize to 100.0, sort descending, map to `ColorCoverage(hex, rgb, percent)`. Handle `total = 0` (fully transparent) → return `[]`.
6. **Examples.** `analyze_from_path.php` and `analyze_from_handle.php` using `AnalyzerFactory::createDefault()` and `analyzeAsJson()`.
7. **End-to-end integration test** (lead it) — see §9.
8. **README + ADR-003 + docblocks.** Fold in A's loader note and B's tolerance subsection.

---

## 7. Internal Milestones and Weekly Timeline

**Week 1 — Design & harness:**
- Read frozen contracts; write ADR-003 (algorithm + rationale).
- Build the `ColorHistogram` skeleton and histogram tests against `SyntheticImageFactory`.
- Stand up clustering tests using the `PassthroughCropper` + `FakeImageLoader` fakes (you don't need B's real cropper yet).
- Exit: histogram design fixed; ADR-003 drafted; red tests in place.

**Week 2 — Core algorithm:**
- Implement `ColorHistogram`, weighted k-means, k-means++ init, `KSelector`, merge pass.
- Determinism + grouping tests green.
- Exit: M2 — `KMeansClusterer` merged, ≥90% covered, PHPStan clean, deterministic.

**Week 3 — Coverage, integration, examples:**
- Implement `PercentageCoverageCalculator` (sum-to-100).
- Lead facade wiring + end-to-end test on synthetic bands and real images; performance tuning (bits, silhouette cost).
- Write examples; start README assembly.
- Exit: M3 — end-to-end result correct; percentages sum ~100; examples run.

**Week 4 — Hardening & release:**
- Mutation testing on clustering/coverage math; finalize README + ADR-003; edge hardening (mono-color, fully transparent, tiny images).
- Exit: M4 — acceptance met; docs complete; `v1.0.0` ready.

---

## 8. Unit Testing Responsibilities

Suites: `tests/Unit/ColorClusterer/`, `tests/Unit/CoverageCalculator/`. Use `SyntheticImageFactory::bands()` for exact ground truth.

- **Histogram:** known image → expected bin count and `total`; transparent pixels excluded from `total`; representative color equals the average of contributing pixels.
- **Grouping:** `bands()` of red/green/blue at 50/30/20 → ~3 clusters with centroids near the inputs.
- **Determinism:** identical input + `seed` → identical centroids, weights, and order (assert on serialized result).
- **Automatic k:** two obvious clusters → `k = 2`; five well-separated colors → `k = 5`; single color → `k = 1`.
- **Merge pass:** input with a color plus a near-duplicate within `mergeDeltaE` → merged into one; a <1% speckle color → folded away.
- **Transparency:** image with an alpha region → those pixels excluded; coverage computed over opaque pixels only.
- **Coverage sum:** for any clustering, `sum(coverage_percent) == 100.0` exactly; empty/transparent → `[]`.
- **Ordering:** results sorted descending by coverage; stable tie-break.
- **Coverage accuracy:** `bands()` 50/30/20 → percentages within tolerance of 50/30/20.

Testing strategy: assert clustering quality via **centroid proximity** (ΔE to expected) and **weight fractions**, not exact float equality of centroids; assert coverage via exact sum + per-color tolerance. Add a **high-resolution performance test** (e.g., 4000×4000 synthetic) that must finish under a time budget, proving binning delivers resolution independence.

---

## 9. Integration Responsibilities

- **Lead the end-to-end integration test** (`tests/Integration/EndToEndTest.php`): once modules land, write a synthetic PNG of known bands to a `php://temp` handle, run `AnalyzerFactory::createDefault()->analyze($handle)`, and assert the returned colors + that percentages sum to ~100.
- Co-lead the **Week 3 facade wiring** with A: your `KMeansClusterer` + `PercentageCoverageCalculator` replace the fakes.
- Confirm the contract with B: the facade unwraps `CropResult->raster` and hands you a `Raster`; you never call B's code directly.
- Validate on **real** PNG/JPEG samples (curate `tests/Fixtures/real/` with A and B).

---

## 10. Required Interfaces and Dependencies from Others

**Consume from A (frozen Week 1; foundation shipped Week 1):**
- `Raster`, `ColorRGBA`, `Cluster`, `ClusterResult`, `ColorCoverage` (contracts).
- `ClustererInterface`, `CoverageCalculatorInterface` (implement them).
- `ClusterOptions` (`fixedK`, `kMax`, `histogramBitsPerChannel`, `mergeDeltaE`, `minClusterCoverage`, `seed`, `alphaThreshold`).
- `Color\ColorConverter` (`rgbToLab`, `deltaE`) — core to clustering.
- `InMemoryRaster`, `SyntheticImageFactory`, `FakeImageLoader`, `PassthroughCropper` — for tests without B's cropper.
- `AnalyzerFactory` / facade skeleton — for examples and the integration test.

**Consume from B:** the cropped `Raster` at integration (Week 3). You are **not blocked by B** during Weeks 1–2 because you test with `PassthroughCropper`.

**You must deliver to unblock others:** your modules are the last pipeline stage and gate M3 — deliver clustering by end of Week 2 and coverage early Week 3 so integration can complete on schedule.

---

## 11. Expected Deliverables

- `ColorHistogram`, `KMeansClusterer`, `KSelector`, `PercentageCoverageCalculator`, fully tested.
- The end-to-end integration test.
- `examples/analyze_from_path.php`, `examples/analyze_from_handle.php`.
- `docs/ADR-003-clustering.md` and the assembled `README.md`.

---

## 12. Definition of Done (Developer C)

- Similar colors grouped via k-means++ in Lab over a weighted histogram; transparent pixels ignored.
- Automatic `k` (or honored `fixedK`) within `kMax`; deterministic for a fixed seed.
- Each principal color returned as hex + rgb + `coverage_percent`; list sorted descending; **percentages sum to exactly 100.0**.
- Resolution-independent within the performance budget (binning verified).
- JSON output matches the assignment example shape.
- PHPStan level 8 clean; PSR-12; examples run; README + ADR-003 complete.

---

## 13. Risks and Mitigations (your scope)

- **Non-deterministic k-means → flaky tests.** → Seeded RNG (`mt_srand`), deterministic tie-breaks; determinism is itself a test.
- **Anti-aliasing/compression halos as fake principal colors.** → Histogram binning + merge by `mergeDeltaE` + drop below `minClusterCoverage`.
- **Silhouette cost O(n²) in bins.** → Bins are already reduced; cap unique colors (reduce bits or take top-N by weight) before silhouette; offer an O(n) fallback (e.g., Calinski-Harabasz) if needed.
- **Percentages not summing to 100.** → Largest-remainder normalization; explicit sum test.
- **Quantization bias skews centroids.** → Use per-bin averaged representative color, not bin center.
- **Needing Lab→RGB inversion.** → Avoid by averaging member RGB; only request `labToRgb` from A via ADR if unavoidable.
- **Fully transparent / mono-color inputs.** → Explicit handling (`[]` and single-cluster paths).

---

## 14. Code Review Responsibilities

- Per `CODEOWNERS`, you are a **required reviewer on B's cropper** PRs — check transparency handling and `Raster` usage stay consistent with your assumptions.
- Your own PRs are reviewed by **A** (contract adherence) and **B**.
- Enforce: PSR-12, PHPStan clean, tests added, deterministic behavior, conventional-commit titles, no self-merge.

---

## 15. Git Workflow Expectations

- Branches: `feat/color-clustering-coverage`, plus follow-ups like `feat/kselector-silhouette`, `docs/readme-assembly`.
- **Conventional Commits**: `feat(clusterer): k-means++ over weighted histogram`, `feat(coverage): largest-remainder normalization`, `docs(readme): usage + output format`.
- Small PRs; CI green before review; **squash-merge**; no self-merge; rebase on `main` before opening a PR.

---

## 16. Documentation Responsibilities

- `docs/ADR-003-clustering.md` — algorithm, color space, k selection, merge rationale, rejected alternatives (median-cut/octree, DBSCAN/mean-shift).
- **Assemble the final `README.md`** — install, usage (path + handle), options, output format, limitations — folding in A's loader note and B's tolerance subsection.
- The two example scripts, kept runnable.
- Inline docblocks with units (weights, percentages, Lab).

---

## 17. Performance Considerations

- **Histogram binning is the core lever** — clustering runs on bins (bounded), not pixels; this delivers resolution independence and smooths noise.
- **Bits vs accuracy:** 5 bits/channel is the default sweet spot; more bits = more bins = slower but finer. Expose via `histogramBitsPerChannel`.
- **Cap silhouette cost:** keep the bin set small (or top-N by weight) before O(n²) silhouette; log WCSS for elbow.
- **Bound k-means:** cap Lloyd iterations; reuse the Lab projection across `k` trials in `KSelector`.
- **Single pass over pixels** in the histogram; everything after operates on the reduced bin set.
- Add the high-resolution timing test as a regression guard.

---

## 18. Architectural Constraints

- Consume `Raster` / `ClusterResult`; produce `ClusterResult` / `ColorCoverage[]`; never touch loader/cropper internals.
- No output, no `exit`, no globals; fully deterministic given a seed.
- All tuning via `ClusterOptions`; no undocumented magic numbers.
- Never leak GD/Imagick types; operate through the `Raster` interface and DTOs only.
- Output must match the agreed JSON shape (`color`, `coverage_percent`).

---

## 19. Task Checklist

- [ ] Draft ADR-003 (algorithm + rationale) and confirm frozen `ClusterOptions`.
- [ ] Implement `ColorHistogram::build()` (weighted bins, transparency skip, averaged representative).
- [ ] Lab-project bins via `ColorConverter`.
- [ ] Implement weighted k-means + k-means++ (seeded) + Lloyd iterations.
- [ ] Implement `KSelector::select()` (silhouette + elbow, bounded, degenerate cases).
- [ ] Implement merge pass (`mergeDeltaE`, `minClusterCoverage`).
- [ ] Implement `PercentageCoverageCalculator` (largest-remainder → 100.0, sort desc, `total=0` → []).
- [ ] Determinism, grouping, transparency, coverage-sum, and ordering tests green.
- [ ] High-resolution performance test within budget.
- [ ] Lead end-to-end integration test; co-lead facade wiring.
- [ ] Write examples; assemble README; finalize ADR-003; docblocks.

## 20. Week-by-Week Roadmap

| Week | Focus | Exit criterion |
|------|-------|----------------|
| 1 | ADR-003, histogram design, test harness with fakes | Histogram design fixed; red tests ready |
| 2 | Histogram + k-means++ + KSelector + merge | M2: clusterer merged, deterministic, ≥90% covered |
| 3 | Coverage, integration, examples, perf tuning | M3: end-to-end result; sum ~100; examples run |
| 4 | Mutation testing, README/ADR, edge hardening | M4: acceptance met; `v1.0.0` ready |

## 21. Final Acceptance Checklist (Developer C)

- [ ] Colors grouped by clustering; principal colors returned (not per-pixel).
- [ ] Coverage percentage per color; **sum exactly 100.0**.
- [ ] Transparent pixels ignored in numerator and denominator.
- [ ] Automatic `k` (or `fixedK`) within `kMax`; deterministic for a fixed seed.
- [ ] Resolution-independent within the performance budget.
- [ ] JSON output matches the assignment shape; examples run from path and handle.
- [ ] Tests green (unit + integration + perf); PHPStan L8; PSR-12.
- [ ] README + ADR-003 complete and current.
