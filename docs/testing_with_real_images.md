# Testing with Real Images

## 1. Install dependencies
```
composer install
```

## 2. Verify the `gd` extension is enabled
```
php -m | findstr /i gd
```

## 3. Run against bundled real-image fixtures
```
php examples/analyze_from_path.php tests/Fixtures/real/sample.png
php examples/analyze_from_handle.php tests/Fixtures/real/sample.jpg
```

## 4. Try edge-case fixtures (loader/cropper paths)
```
php examples/analyze_from_path.php tests/Fixtures/real/sample_transparent.png
php examples/analyze_from_path.php tests/Fixtures/real/transparent_border.png
php examples/analyze_from_path.php tests/Fixtures/real/logo_white_border.png
php examples/analyze_from_path.php tests/Fixtures/real/scan_offwhite_border.jpg
```

## 5. Test with your own image (path API)
```
php examples/analyze_from_path.php C:\path\to\your\image.jpg
```

## 6. Test the raw-bytes API path (file handle)
```
php examples/analyze_from_handle.php C:\path\to\your\image.png
```

## 7. Sanity-check output
Confirm the JSON has plausible colors/percentages that sum close to 100%, and that no PHP warnings/errors are printed to stderr.

## 8. Test malformed/non-image input (error handling)
Pass any non-image file path and confirm it throws a clean exception rather than a PHP warning/fatal.

## Example run

```
php examples/analyze_from_path.php tests/Fixtures/real/colorful.jpeg
```

Output:
```json
[
    { "color": "#3671AB", "coverage_percent": 24.8 },
    { "color": "#BF5E51", "coverage_percent": 19.1 },
    { "color": "#EEF1F2", "coverage_percent": 15 },
    { "color": "#343230", "coverage_percent": 10.3 },
    { "color": "#7C9D58", "coverage_percent": 9.4 },
    { "color": "#E8C749", "coverage_percent": 8.6 },
    { "color": "#51815B", "coverage_percent": 7.7 },
    { "color": "#B85C87", "coverage_percent": 5.1 }
]
```

Coverage percentages sum to 100%.
