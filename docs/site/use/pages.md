---
title: Static pages
description: Create About, Terms and Contact pages in ShopClass with the rich-text editor or the block-based page builder, and place forms on them.
sidebar:
  order: 9
---

Every classifieds site needs a handful of pages that are not listings: About,
Terms, Privacy, How it works, Contact.

**Pages** in the admin panel.

## Creating a page

**Pages → Add page**. A page has a title and body per active language, and a
URL slug (the name as it appears in web addresses) taken from the title.

To link a page from the site footer, tick **Show a link in the footer** in the
page's settings. It is off for a new page. Deleting a page also removes its
footer link, its blocks and its uploaded images.

## Two ways to build one

Pick the **Page template** field when creating the page:

- **Default template**: the text editor. A rich-text field, right for prose.
  Terms and Privacy want exactly this.
- **Page builder (blocks)**: composes the page from blocks instead.
  Right for a landing page or a How-it-works page with images and sections.

**Add block** shows the block types as tiles in three groups. **Layout** blocks belong to pages only:

| Block | What it is |
|---|---|
| **Image and text** | A picture beside text, left or right, half or a third wide, with an optional button. |
| **Columns** | Two or three columns of text side by side. |
| **Heading** | A section title, left or centred, with an optional intro line. |
| **Button** | One or two links drawn as buttons, left or centred. |

**Content** blocks are the types used on the Widgets screen too:

| Block | What it is |
|---|---|
| **Rich text** | Formatted text: headings, bold, lists and links. |
| **Image** | An image from your media library, optionally linking somewhere. |
| **Form** | A form from **Forms**. |

**Advanced** holds **Custom Code (HTML / JavaScript)**: raw markup and script, with a warning.
Only a super admin can add it.

Images come from one picker, in both the text editor and image blocks: upload a file or drop it
on the window, pick one from the library, or add a link to an image elsewhere.

Click a picture in the text editor for its toolbar: **Wrap text, picture on the left** or **on the
right** floats it at up to half the width with the text beside it, **Centre** puts it on its own
line, and **No alignment** clears it. On a phone a wrapped picture takes its own line.

**Rich text** and **Page text** blocks choose a **Width** (reading width or full width) and, at
reading width, a **Position** (left or centred).

On a phone every block stacks to one column, the picture first. Plugins can register
further block types.

A page that opens with its own heading can tick **Hide the page title** in **Page settings**.
The title is then hidden on screen, but screen readers and search engines still get it.

**Edit** in the Pages list opens a Page builder page's block editor; **Page settings** (in the list
and in the block editor) opens the page form for the title, template and footer link. The page
form shows a summary of the blocks, a note when you have unpublished changes, and an
**Edit blocks** button. Saving a page keeps it open. A new page is saved first; saving a page with
the Page builder template for the first time opens its blocks.

### Edit blocks

**Edit blocks** opens the **Customise** screen, beside a live preview of the page, on any theme.
It is at `?page=pages&action=blocks&id=<page id>` in the admin. The block name is optional; an
empty name takes the type's name.

- The left panel lists the blocks. Drag one, or use the arrow keys, to move it. Pick a block in the
  list, or click it in the preview, to open its fields. **All blocks** goes back to the list.
- **Add block** shows the block types as tiles. **Remove** is inside the block's fields.
- **Expand** beside a text field opens the editor large, with its full toolbar. **Done** puts the
  text back in the block.
- Changes are saved as your own draft for seven days and shown in the preview. Nothing is live
  until you press **Publish**. **Discard** drops the draft.
- The **Page text** block edits the page's own text, with one tab per site language. Picking a
  language also shows the preview in it. The text is part of your draft and goes live with the
  blocks on **Publish**. On a Page builder page the page form no longer shows the text editor.
- If the blocks or the page text were changed elsewhere after your draft began, Publish asks before
  it replaces them.
- Old blocks with no type are read-only for every admin. A moderator who may open Pages also sees
  Custom Code blocks read-only: they can move them but not edit or remove them, and cannot add Custom Code.

A page builder page renders correctly on **any** theme. If the active theme
ships its own `template-widgets.php` the theme owns the layout; otherwise core
wraps the theme's header and footer around the blocks.

## Forms on a page

A [form](/docs/use/forms-and-custom-fields/) can be placed on a page to collect
submissions: a contact form, an application, an enquiry form. Responses arrive
in **Forms → Submissions** rather than only by e-mail, so nothing is lost if
mail delivery fails.

## Pages worth having

Beyond taste, some of these are load-bearing:

- **Terms** and **Privacy**: required by most payment providers and by law in
  many jurisdictions, and the first thing a user checks before posting personal
  contact details.
- **Contact**: an actual route to a human. A marketplace with no contact page
  reads as a scam.
- **How it works**: the page that converts a visitor into a first-time poster.
- **About**: who runs the site. On a local marketplace this is worth more than
  any feature.

## Editing safely

- **Changing a title changes the URL.** Rename early or not at all.
- **Custom Code blocks run on visitors' browsers.** Paste only what you
  understand, from a source you trust.
- **Write each language.** A page with an empty translation shows empty to
  visitors in that language: see [languages](/docs/use/languages/).
