# Portable Source Manifest v1

`static-site-importer/portable-source/v1` declares the exact deployable website payload transported to Static Site Importer. Place the manifest at `.static-site-importer-source.json` at the transport root.

```json
{
  "schema": "static-site-importer/portable-source/v1",
  "root": "website",
  "entrypoint": "index.html",
  "files": [
    {
      "path": "index.html",
      "sha256": "4f83..."
    },
    {
      "path": "css/site.css",
      "sha256": "b7ac..."
    }
  ]
}
```

Paths in `files` and `entrypoint` are relative to `root`. If an intake adapter places the complete transport under a wrapper directory, SSI discovers the manifest there and resolves `root` relative to that directory. The manifest is transport metadata and is never part of the compiled website artifact. SSI verifies every declared SHA-256 hash, rejects missing or duplicate declarations, ignores transported files outside the explicit inventory, rebases declared paths to the website root, and passes only the projected payload to Blocks Engine.

Without this manifest, the complete transported file set remains the website payload. This preserves deterministic directory and archive behavior without filename-specific exclusions.
