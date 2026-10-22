---
title: "Second delivery to staging"
description: "Delivering new pages to a staging site that already has the media from the first delivery — without duplicating the images and without links to the local site."
---

## The scenario

The first delivery to staging brought the pages and their images. A week later there are two new pages that reuse some of those images, plus one new image. The second export carries them all; staging already has most of the images.

## The steps

1. On the local site, **Tools → Export**: check the two new pages (their images come along).
2. **Export**, and on staging **Tools → Import → WordPress** with the file — *Update existing* on or off.

## What the import does

- The images staging already has (same title and date as in the file) are **reused**, not imported again.
- Their addresses in the new pages — the full size and every image size (`photo-300x200.jpg`) — point to the files on staging, not to the local site. Before, this only happened with *Update existing* on.
- The new image is imported, and the blocks point to it ([galleries to staging](../galleries-to-staging/)).

## What it doesn't

- An image whose file changed locally keeps staging's old file: turn on *Update existing* to update pages, and replace the image in the media library.
