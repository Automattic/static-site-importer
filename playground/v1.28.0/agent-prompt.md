# Spawn a WordPress site from generated HTML

Paste this to your coding agent. Nothing else to write — it will ask you what
you want. Works with any agent that can write files and run a script; no plugin,
MCP server, or account required.

---

You are going to build me a website and turn it into a real WordPress site I can
open in my browser. Follow these steps in order.

**Step 0 — Ask me what I want, before writing anything.**
Do not generate any files yet. Ask me these, all in one short message:

- What is the site for? (business, project, portfolio, event, something else)
- What should it be called, and is there a one-line description?
- Which pages do you want? Suggest a sensible starting set and let me adjust.
- Any look you have in mind — colours, mood, a site you like?

Keep it brief and conversational. If I answer vaguely, or say something like
"you pick" or "surprise me", stop asking and choose good defaults yourself:
a five-page site, a clear typographic layout, and a restrained colour palette.
Never block on a question I have already answered well enough to start.

Once I have answered, briefly confirm what you are about to build in one or two
sentences, then continue without waiting for further approval.

**Step 1 — Write plain static HTML.**
Create a folder `site/` with `index.html` at its root, plus one file per page
(`about.html`, `contact.html`, …). Use ordinary semantic HTML: headings,
paragraphs, lists, images, links between pages. Put CSS in a `<style>` tag in
each page's `<head>`. No JavaScript frameworks, no build step, no server-side
code.

**Step 2 — Turn it into a WordPress site.**
Write a script that builds this JSON, where `STEPS` is described below:

```json
{
  "landingPage": "/",
  "preferredVersions": { "php": "8.3", "wp": "latest" },
  "login": true,
  "steps": [ STEPS ]
}
```

`STEPS` is, in order:

1. Install the importer:
   ```json
   {"step":"installPlugin",
    "pluginData":{"resource":"url",
      "url":"https://github.com/Automattic/static-site-importer/releases/latest/download/static-site-importer.zip"},
    "options":{"activate":true,"targetFolderName":"static-site-importer"}}
   ```
2. Install the WordPress.com handoff, so I can keep the site:
   ```json
   {"step":"installPlugin",
    "pluginData":{"resource":"url",
      "url":"https://automattic.github.io/static-site-importer/playground/latest/playground-to-wordpress-com.zip"},
    "options":{"activate":true,"targetFolderName":"playground-to-wordpress-com"}}
   ```
3. The import request — the exact input the importer's own contract takes:
   ```json
   {"step":"writeFile","path":"/tmp/ssi-request.json","data":"{
     \"operation\": \"apply\",
     \"slug\": \"generated-site\",
     \"name\": \"Generated Site\",
     \"site_title\": \"SITE TITLE HERE\",
     \"activate\": true,
     \"overwrite\": true,
     \"runtime_lifecycle_phase\": \"prepare\",
     \"source\": { \"type\": \"files\", \"entrypoint\": \"website/index.html\",
       \"files\": [ { \"path\": \"website/index.html\", \"content\": \"...\" } ] }
   }"}
   ```
   `files` carries every generated page, each `path` prefixed with `website/`.
   Use the name I gave you in Step 0 for `site_title`.
4. **(number of pages + 5)** copies of this identical step:
   ```json
   {"step":"wp-cli",
    "command":"wp static-site-importer import --single-step --request=/tmp/ssi-request.json --state=/tmp/ssi-state.json"}
   ```
   The importer compiles roughly one page per invocation and keeps its own
   continuation state, so every step is byte-identical and you never handle an
   import id. Calls after the import finishes replay its receipt instead of
   starting a second one, so listing more steps than needed is safe and is the
   reason you do not need to predict the page count.

**Step 3 — Give me the link.**
Base64-encode the JSON (UTF-8, standard alphabet) and print one line:

```
https://playground.wordpress.net/#<base64>
```

Give me the URL and a one-line summary of what you built — nothing else. Tell me
it opens a real WordPress site I can edit, that it takes a minute to boot, and
that closing the tab discards it unless I use **Move to WordPress.com** in the
toolbar.

Keep the URL under ~60,000 characters. As a guide the link runs about 6 KB plus
roughly 1.4x the size of the site, so a text-and-CSS site of twenty pages lands
near 33 KB. Images must be base64-encoded inline, which grows it quickly, so
prefer CSS colour, type and layout over photographs. If the site is getting too
large, say so and offer to trim pages rather than failing.

**Then ask what I would like to change**, and keep going as long as I want.
On every change: update the files in `site/`, rebuild the blueprint from **all**
pages, and give me a new URL. Every link is a fresh site — `site/` is the source
of truth, and the WordPress site is the rendered output.
