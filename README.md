# Resource Pack Hosting

Host Minecraft resource packs directly on your Pelican panel.

## Features

- **Setup menu** – an animated setup dialog opens for root admins until the plugin is configured. Settings can be changed later under *Admin → Advanced → Resource Packs* or in the plugin settings.
- **Egg selection** – enable the plugin for specific eggs, or let it detect Minecraft eggs automatically (tag `minecraft`, `eula` feature or names like Paper, Purpur, Folia, Fabric, Velocity …).
- **Storage quota** – total storage every server may use for its packs (in GB).
- **Server page** – a new *Resource Packs* entry in the server sidebar: upload a ZIP, get a download link and SHA-1, copy them or write them straight into `server.properties`.
- **Safe packs** – every upload is checked and rebuilt:
  - `pack.mcmeta` must be in the root and contain a valid `pack_format` (or `min_format` for 1.21.9+). A pack zipped together with its folder is unwrapped automatically.
  - Only resource pack file types are allowed (`png`, `ogg`, `json`, `mcmeta`, `txt`, `properties`, `lang`, shaders, fonts …). Executables, scripts, JARs, HTML, nested archives and symlinks are rejected.
  - File contents must match their extension (a `.png` has to be a PNG); disguised executables are rejected.
  - Path traversal and zip bombs are blocked.
  - The pack is re-zipped from the checked files only, so ZIP comments or data appended to the archive never reach players.
- **Protected download links** – random 64-character links, optionally only valid for the Minecraft Java client (browsers get a 404), rate limited, and regenerable at any time.
- **Subuser permissions** – `resourcepack.read`, `create`, `update` and `delete`.

## Note on leaking

The Minecraft client always has to download the pack to the player's PC to use it. No server can prevent that completely. This plugin makes the download link hard to misuse: it is random, can be limited to the Minecraft client and can be replaced with one click. A player who wants to can still copy the pack from their own `.minecraft` folder.

## Configuration (.env, optional)

| Variable | Default | Description |
|---|---|---|
| `RESOURCE_PACK_HOSTING_MAX_UPLOAD_MB` | 100 | Max. size of a single upload (PHP `upload_max_filesize` / `post_max_size` and nginx `client_max_body_size` must allow it too) |
| `RESOURCE_PACK_HOSTING_MAX_UNCOMPRESSED_MB` | 1024 | Max. unpacked size of a pack |
| `RESOURCE_PACK_HOSTING_MAX_ENTRIES` | 30000 | Max. number of files in a pack |
| `RESOURCE_PACK_HOSTING_MAX_COMPRESSION_RATIO` | 250 | Zip-bomb protection: max. compression ratio per file |
| `RESOURCE_PACK_HOSTING_DOWNLOADS_PER_MINUTE` | 30 | Download rate limit per IP |
