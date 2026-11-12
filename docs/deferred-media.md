# Deferred capture media

A capture made with deferred media (`data-liberation-agent --defer-media`)
lists its media in `media-queue.json` (`data-liberation-agent/media-queue/v1`).
Its documents reference each file as `/deferred-media/<hash>/<name>`, and
`data-liberation media <capture>` downloads those files into the capture's
`website/deferred-media/` tree.

## Content import

Request-bundle intake reads the queue from the capture root:

- Files the queue lists stay out of the compile, the theme copy and the
  request-bundle media budget. Every other binary keeps the request-bundle
  limits.
- Each document reference to a queued file becomes the file's source URL
  (`originalUrl`, else `url`). The imported content works before any media
  is imported.
- The completed import receipt carries a `media` block with the stage `ref`
  and the `pending` count.

Keep the capture directory in place until the media stage finishes.

## Media stage

```sh
wp static-site-importer media ssi-media-<hash> --batch=50
```

The matching `static-site-importer/media` ability takes `ref` and `batch`
(at most 500). Each run handles the following:

- It imports one batch of queue entries into the Media Library.
- It rewrites the batch's source URLs to attachment URLs in the posts that
  import created.
- On image blocks, it sets the attachment `id` and the `wp-image-<id>` class.

Repeat the run until `pending` is 0. Each receipt reports `pending`,
`imported` and `failed`; `failed` names each queue path once, with its
reason, such as `source_failed` or `file_missing`. A failed entry's content
keeps its source URL.

Progress is journaled in a non-autoloaded option, keyed by queue path:

- A stopped worker resumes from the same `ref`.
- Attachments carry their queue identity, so a batch that is interrupted
  before its journal write repeats without duplicating them.
- A lock file refuses a second worker for the same stage. The lock is
  released when a worker exits or is killed.
