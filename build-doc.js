const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    Header, Footer, AlignmentType, HeadingLevel, BorderStyle, WidthType,
    ShadingType, PageNumber, PageBreak, TableOfContents, LevelFormat,
} = require('docx');
const fs = require('fs');

const BLUE = "2E75B6";
const DARK = "1F3864";
const LIGHT_BG = "EBF3FB";

function h1(text) {
    return new Paragraph({
        heading: HeadingLevel.HEADING_1,
        pageBreakBefore: true,
        children: [new TextRun({ text, bold: true, color: DARK })]
    });
}

function h2(text) {
    return new Paragraph({
        heading: HeadingLevel.HEADING_2,
        children: [new TextRun({ text, bold: true, color: BLUE })]
    });
}

function body(text) {
    return new Paragraph({
        children: [new TextRun({ text, size: 24 })],
        spacing: { after: 160 }
    });
}

function bullet(text, boldPart) {
    const children = [];
    if (boldPart) {
        children.push(new TextRun({ text: boldPart, bold: true, size: 24 }));
        children.push(new TextRun({ text: text.slice(boldPart.length), size: 24 }));
    } else {
        children.push(new TextRun({ text, size: 24 }));
    }
    return new Paragraph({
        numbering: { reference: "bullets", level: 0 },
        children,
        spacing: { after: 100 }
    });
}

function numItem(ref, text, boldPart) {
    const children = [];
    if (boldPart) {
        children.push(new TextRun({ text: boldPart, bold: true, size: 24 }));
        children.push(new TextRun({ text: text.slice(boldPart.length), size: 24 }));
    } else {
        children.push(new TextRun({ text, size: 24 }));
    }
    return new Paragraph({
        numbering: { reference: ref, level: 0 },
        children,
        spacing: { after: 100 }
    });
}

function spacer() {
    return new Paragraph({ children: [new TextRun("")], spacing: { after: 200 } });
}

function divider() {
    return new Paragraph({
        border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: BLUE, space: 1 } },
        children: [new TextRun("")],
        spacing: { after: 200 }
    });
}

function noteBox(text) {
    return new Paragraph({
        children: [new TextRun({ text, size: 24, italics: true, color: "555555" })],
        border: {
            left: { style: BorderStyle.SINGLE, size: 12, color: BLUE, space: 8 }
        },
        indent: { left: 400 },
        spacing: { before: 120, after: 200 }
    });
}

const doc = new Document({
    numbering: {
        config: [
            {
                reference: "bullets",
                levels: [{ level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 720, hanging: 360 } } } }]
            },
            {
                reference: "num1",
                levels: [{ level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 720, hanging: 360 } } } }]
            },
            {
                reference: "num2",
                levels: [{ level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 720, hanging: 360 } } } }]
            },
            {
                reference: "num3",
                levels: [{ level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 720, hanging: 360 } } } }]
            },
            {
                reference: "num4",
                levels: [{ level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 720, hanging: 360 } } } }]
            },
        ]
    },
    styles: {
        default: {
            document: { run: { font: "Arial", size: 24, color: "333333" } }
        },
        paragraphStyles: [
            {
                id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
                run: { size: 36, bold: true, font: "Arial", color: DARK },
                paragraph: { spacing: { before: 320, after: 200 }, outlineLevel: 0 }
            },
            {
                id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
                run: { size: 28, bold: true, font: "Arial", color: BLUE },
                paragraph: { spacing: { before: 240, after: 120 }, outlineLevel: 1 }
            },
        ]
    },
    sections: [

        // ── COVER PAGE ──────────────────────────────────────────────────────
        {
            properties: {
                page: {
                    size: { width: 12240, height: 15840 },
                    margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 }
                }
            },
            children: [
                new Paragraph({ children: [new TextRun("")], spacing: { after: 2880 } }),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: "Media Usage Tracker", bold: true, size: 64, color: DARK, font: "Arial" })]
                }),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: "MUT", bold: true, size: 52, color: BLUE, font: "Arial" })]
                }),
                spacer(),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: BLUE, space: 1 } },
                    children: [new TextRun("")]
                }),
                spacer(),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: "A WordPress Plugin Development Journey", size: 36, color: "555555", font: "Arial" })]
                }),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: "From Idea to Working Plugin", size: 32, italics: true, color: "777777", font: "Arial" })]
                }),
                new Paragraph({ children: [new TextRun("")], spacing: { after: 2400 } }),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: "June 19, 2026", size: 26, color: "555555", font: "Arial" })]
                }),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: "Trusted Digital Agency", size: 26, bold: true, color: BLUE, font: "Arial" })]
                }),
                new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: "support@trusteddigitalagency.com", size: 24, color: "777777", font: "Arial" })]
                }),
            ]
        },

        // ── CONTENT ─────────────────────────────────────────────────────────
        {
            properties: {
                page: {
                    size: { width: 12240, height: 15840 },
                    margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 }
                }
            },
            footers: {
                default: new Footer({
                    children: [new Paragraph({
                        alignment: AlignmentType.CENTER,
                        children: [
                            new TextRun({ text: "Media Usage Tracker — Build Journey  |  Page ", size: 20, color: "777777" }),
                            new TextRun({ children: [PageNumber.CURRENT], size: 20, color: "777777" }),
                            new TextRun({ text: " of ", size: 20, color: "777777" }),
                            new TextRun({ children: [PageNumber.TOTAL_PAGES], size: 20, color: "777777" }),
                        ]
                    })]
                })
            },
            children: [

                // TOC
                new Paragraph({
                    heading: HeadingLevel.HEADING_1,
                    pageBreakBefore: false,
                    children: [new TextRun({ text: "Table of Contents", bold: true, color: DARK })]
                }),
                new TableOfContents("Table of Contents", { hyperlink: true, headingStyleRange: "1-2" }),
                new Paragraph({ children: [new PageBreak()] }),

                // ── INTRODUCTION ────────────────────────────────────────────
                new Paragraph({
                    heading: HeadingLevel.HEADING_1,
                    children: [new TextRun({ text: "Introduction", bold: true, color: DARK })]
                }),
                divider(),
                body("Media Usage Tracker — or MUT for short — is a WordPress plugin built to answer one simple question: where is each image or file on your website actually being used?"),
                body("Before MUT, website owners had no easy way to know which files in their media library were actively being used on pages, posts, or products — and which ones were just sitting there taking up space. MUT solves that by scanning your website and showing you exactly where every file appears, or flagging it as unused if nothing is referencing it."),
                body("The plugin also helps with common media maintenance tasks: generating missing alt text using AI, reviewing and cleaning up unused files safely, checking for oversized images, finding duplicates, and exporting reports. It works with all the major WordPress page builders and plugins out of the box."),
                spacer(),

                // ── PHASE 1 ─────────────────────────────────────────────────
                h1("Phase 1 — The Starting Idea"),
                divider(),
                new Paragraph({
                    children: [
                        new TextRun({ text: "The request was simple: ", bold: true, size: 24 }),
                        new TextRun({ text: "\"Build a WordPress plugin that tracks which media files are in use and which are not.\"", italics: true, size: 24 })
                    ],
                    spacing: { after: 200 }
                }),
                body("From that single sentence, the entire plugin was planned and built. The first step was setting up the basic structure — the folder layout, the database where usage information would be stored, and the core engine that would do the actual scanning."),
                body("Two basic detectors were built first: one to find images set as a page or post's featured image, and another to find images embedded directly inside the content of a page or post. A simple dashboard was added so you could kick off a scan and see the results."),
                spacer(),

                // ── PHASE 2 ─────────────────────────────────────────────────
                h1("Phase 2 — Supporting More Plugins"),
                divider(),
                body("Most WordPress websites use page builders or third-party plugins to manage their content. MUT needed to understand how each of those tools stores images — because every plugin does it differently."),
                body("Detectors were built one by one for each major plugin:"),
                spacer(),
                numItem("num1", " Elementor — finds images placed inside Elementor-designed pages, including carousels, galleries, sliders, and background images. It covers every type of image widget automatically.", "Elementor"),
                numItem("num1", " JetEngine — finds images attached to custom content types (like team members, properties, or services).", "JetEngine"),
                numItem("num1", " JetPopup — finds background and overlay images set on popups.", "JetPopup"),
                numItem("num1", " WooCommerce — finds product images, gallery photos, variation images, and category images.", "WooCommerce"),
                numItem("num1", " ACF (Advanced Custom Fields) — finds images stored in custom image fields, and shows which field the image came from.", "ACF (Advanced Custom Fields)"),
                numItem("num1", " Yoast SEO — finds the social sharing image (the one shown when a page is shared on Facebook or other platforms).", "Yoast SEO"),
                numItem("num1", " Gravity Forms — finds images embedded inside contact form fields and confirmation messages.", "Gravity Forms"),
                numItem("num1", " Avada / Fusion Builder — finds images placed inside Avada's page builder layouts.", "Avada / Fusion Builder"),
                numItem("num1", " Beaver Builder — finds images in photo modules and gallery layouts.", "Beaver Builder"),
                numItem("num1", " WPBakery Page Builder — finds images in single image and gallery blocks.", "WPBakery Page Builder"),
                numItem("num1", " Divi — supports both the old Divi shortcode format and the new Divi 5 format (which works completely differently under the hood).", "Divi"),
                numItem("num1", " Astra — finds the site logo and any images set through the theme's customizer settings.", "Astra"),
                numItem("num1", " wpDataTables — finds images inside manually-created data tables (planned for a future update).", "wpDataTables"),
                spacer(),
                noteBox("Each detector only runs when the relevant plugin or theme is actually installed and active on the site. If a plugin is not present, its detector is simply skipped."),
                spacer(),

                // ── PHASE 3 ─────────────────────────────────────────────────
                h1("Phase 3 — Finding and Fixing Problems"),
                divider(),
                body("Once the detectors were built, every single one was tested on a real WordPress website with the actual plugins installed. Several problems were discovered — and fixed — during this testing phase."),
                spacer(),
                h2("Issues Found and Resolved"),
                bullet("Gravity Forms was not showing up in the filter options, even when installed. It had been left out of the dropdown list by mistake. Fixed by adding it.", "Gravity Forms"),
                bullet("Beaver Builder images were not being detected even though the detector existed. The name used inside the plugin did not match exactly — a small typo caused the whole thing to silently fail. Fixed by correcting the name.", "Beaver Builder"),
                bullet("Avada images were being labelled as \"In Content\" instead of \"Avada\". Avada had been left out of the priority list that decides how usage types are displayed. Fixed by adding it.", "Avada"),
                bullet("Divi returned zero results. Investigation revealed that Divi 5 completely changed the way it saves content — the old detection method no longer worked at all. The detector was fully rewritten from scratch to support the new format.", "Divi"),
                bullet("Some images with resized versions (WordPress sometimes creates a \"-scaled\" version of large uploads) were not being matched correctly. A fallback was added to catch these.", "Scaled images"),
                bullet("JetPopup showed zero results when filtered. This turned out to be expected — JetPopup's dynamic images are actually managed through JetEngine. A helpful note was added to the screen so users understand why.", "JetPopup"),
                bullet("Some usage labels had coloured badges while others did not, making the display look inconsistent. All badges were changed to the same plain grey style.", "Badge styling"),
                spacer(),

                // ── PHASE 4 ─────────────────────────────────────────────────
                h1("Phase 4 — Search and Filter Page"),
                divider(),
                body("A dedicated Search and Filter page was built so users could easily find specific files in their media library without having to scroll through everything."),
                spacer(),
                h2("What You Can Filter By"),
                bullet("Search by filename or title"),
                bullet("Filter by whether a file is in use or unused"),
                bullet("Filter by file type (images, PDFs, videos, audio)"),
                bullet("Filter by when the file was uploaded"),
                bullet("Filter by file size (large or small)"),
                bullet("Filter by which plugin or page builder is using the file"),
                spacer(),
                h2("Other Features on This Page"),
                bullet("Quick filter shortcuts at the top (e.g., \"Unused Images\", \"Large Files\") so common searches are just one click"),
                bullet("Labels showing which filters are currently active, with an easy way to clear them"),
                bullet("When viewing unused files, a bulk action bar appears so you can select multiple files and move them to trash at once"),
                bullet("Status pills (All / In Use / Unused) that update the count instantly without reloading the page"),
                spacer(),

                // ── PHASE 5 ─────────────────────────────────────────────────
                h1("Phase 5 — Search Using Plain English"),
                divider(),
                body("Instead of setting filters one by one, users can now type a plain English sentence into the AI search bar and MUT will figure out the filters automatically."),
                spacer(),
                h2("Examples of What You Can Type"),
                bullet("\"show unused images\" — filters to images that are not being used anywhere"),
                bullet("\"large PDFs from last month\" — filters to PDFs over 2MB uploaded in the last 30 days"),
                bullet("\"images used by Elementor\" — filters to images referenced in Elementor pages"),
                bullet("\"acf\" or \"gravity forms\" — sets the Referenced By filter to that plugin automatically"),
                bullet("\"photos uploaded this week\" — filters to images from the last 7 days"),
                spacer(),
                body("The search works in two ways. First, it tries to understand the query on its own using a set of built-in rules — no internet connection needed. If the user has set up an AI service (like Google Gemini, Groq, or Anthropic Claude), that can also be used to interpret more complex queries."),
                spacer(),

                // ── PHASE 6 ─────────────────────────────────────────────────
                h1("Phase 6 — Quality Audit"),
                divider(),
                body("The Quality Audit section automatically checks your media library for common issues that could affect your website's accessibility or search engine ranking."),
                spacer(),
                h2("What It Checks"),
                bullet("Missing alt text — images without a description for screen readers (flagged as High priority)"),
                bullet("Missing captions — images with no caption text"),
                bullet("Missing descriptions — images with no description field filled in"),
                bullet("Oversized images — image files that are larger than 2MB and may be slowing down your site"),
                bullet("Missing titles — files with no title set"),
                spacer(),
                h2("Important Design Decision"),
                body("The quality audit only shows files that are actually being used on the site. There is no point flagging an unused image for missing alt text — if nothing is showing that image to visitors, the missing alt text has no impact. This keeps the list focused and actionable."),
                spacer(),
                h2("AI Alt Text Generation"),
                body("For images missing alt text, MUT can use AI to suggest descriptions automatically. You review each suggestion, make edits if needed, and save them — either one at a time or all at once. The same feature works for captions too."),
                spacer(),

                // ── PHASE 7 ─────────────────────────────────────────────────
                h1("Phase 7 — Bulk Review and Safe Deletion"),
                divider(),
                body("The Bulk Review page gives you a place to go through your media files in an organised way — flagging files for review, archiving ones you want to keep track of, or safely removing ones you no longer need."),
                spacer(),
                h2("How Safe Deletion Works"),
                body("Deleting files from a website can be risky — if something is still being used somewhere and you delete it, you end up with broken images. MUT's deletion process was designed with safety in mind:"),
                spacer(),
                numItem("num2", "You click the delete button on a file.", ""),
                numItem("num2", "MUT runs a quick safety check to confirm the file is genuinely not used anywhere.", ""),
                numItem("num2", "If it is safe to delete, you confirm and the file is moved to Trash (not permanently deleted yet).", ""),
                numItem("num2", "From the Trash, you can either restore the file or permanently remove it.", ""),
                spacer(),
                body("The delete button only appears on unused files. Files that are in use only show a View button — so there is no risk of accidentally deleting something that is still needed."),
                spacer(),
                h2("Export Options"),
                body("The full list of files can be exported at any time as a spreadsheet (CSV or Excel) or as a PDF report — useful for sharing with a client or keeping a record."),
                spacer(),

                // ── PHASE 8 ─────────────────────────────────────────────────
                h1("Phase 8 — Media Usage List Improvements"),
                divider(),
                body("The main Media Usage list page — which shows all files in your library — was improved with several quality-of-life updates."),
                spacer(),
                bullet("Three filter pills were added at the top: All, In Use, and Unused. Clicking them filters the list instantly without reloading the page."),
                bullet("When you click \"Files In Use\" on the Dashboard, you now land directly on the Media Usage page with the In Use filter already selected — instead of having to click it yourself."),
                bullet("When the Unused filter is active, checkboxes appear so you can select multiple files and move them all to Trash in one action."),
                bullet("The delete button only appears next to unused files, keeping things clean and safe."),
                bullet("A layout bug was discovered and fixed: the page looked fine in Chrome but broke in Firefox due to a small error in the HTML code. Fixed for both browsers."),
                spacer(),

                // ── PHASE 9 ─────────────────────────────────────────────────
                h1("Phase 9 — Storage Tools and Reports"),
                divider(),
                body("A set of additional tools was built to help with general media library maintenance:"),
                spacer(),
                bullet("Storage Optimization — highlights images that are larger than they need to be and flags them for review."),
                bullet("Duplicate Finder — looks for files that appear to be copies of each other, helping you clean up accidental duplicates."),
                bullet("Reports — lets you export a full summary of your media library as a CSV, Excel, or PDF file."),
                bullet("Trash Bin — a dedicated page where deleted files sit before they are permanently removed. You can restore any file from here with one click."),
                spacer(),

                // ── PHASE 10 ────────────────────────────────────────────────
                h1("Phase 10 — Help Section and Documentation"),
                divider(),
                body("A built-in FAQ section was added directly inside the plugin so users can get answers without leaving WordPress."),
                spacer(),
                h2("What the FAQ Covers"),
                bullet("How the scan works and what it looks at"),
                bullet("A list of all 14 supported plugins and themes, with a plain English description of what each one detects"),
                bullet("A compatibility section explaining known quirks (for example, why JetPopup shows zero results when filtered, or how Divi 5 is handled differently from older Divi)"),
                bullet("A feedback section inviting users to report any plugins or themes that are not yet supported"),
                spacer(),
                body("The description for Elementor was also updated to make it clearer: MUT does not just detect single images in Elementor — it picks up images from every type of element, including carousels, galleries, sliders, and background images."),
                spacer(),

                // ── TECH STACK ───────────────────────────────────────────────
                h1("How It Was Built — A Plain English Summary"),
                divider(),
                body("For those curious about what the plugin is made of:"),
                spacer(),
                new Table({
                    width: { size: 9360, type: WidthType.DXA },
                    columnWidths: [3000, 6360],
                    rows: [
                        new TableRow({
                            children: [
                                new TableCell({
                                    width: { size: 3000, type: WidthType.DXA },
                                    shading: { fill: DARK, type: ShadingType.CLEAR },
                                    margins: { top: 120, bottom: 120, left: 160, right: 160 },
                                    children: [new Paragraph({ children: [new TextRun({ text: "What", bold: true, color: "FFFFFF", size: 24 })] })]
                                }),
                                new TableCell({
                                    width: { size: 6360, type: WidthType.DXA },
                                    shading: { fill: DARK, type: ShadingType.CLEAR },
                                    margins: { top: 120, bottom: 120, left: 160, right: 160 },
                                    children: [new Paragraph({ children: [new TextRun({ text: "Plain English Explanation", bold: true, color: "FFFFFF", size: 24 })] })]
                                }),
                            ]
                        }),
                        ...[
                            ["Built with", "PHP — the programming language that powers most of WordPress"],
                            ["Data storage", "A custom database table created specifically for MUT to store scan results"],
                            ["Admin interface", "Uses WordPress's own styling so it feels native and familiar"],
                            ["AI features", "Optional — connects to Google Gemini, Groq, or Anthropic Claude if you provide an API key"],
                            ["File exports", "CSV and Excel via a spreadsheet library; PDF via a custom built layout engine"],
                            ["Design pattern", "Each plugin detector is a separate, self-contained file — easy to add new ones without touching existing code"],
                        ].map((row, i) => new TableRow({
                            children: row.map((cell, j) => new TableCell({
                                width: { size: j === 0 ? 3000 : 6360, type: WidthType.DXA },
                                shading: { fill: i % 2 === 0 ? LIGHT_BG : "FFFFFF", type: ShadingType.CLEAR },
                                margins: { top: 100, bottom: 100, left: 160, right: 160 },
                                children: [new Paragraph({ children: [new TextRun({ text: cell, size: 24, bold: j === 0 })] })]
                            }))
                        }))
                    ]
                }),
                spacer(),

                // ── LESSONS LEARNED ──────────────────────────────────────────
                h1("What We Learned Along the Way"),
                divider(),
                body("Building MUT taught several lessons that are worth keeping in mind for any future plugin work:"),
                spacer(),
                numItem("num3", " Always test with real content. Divi 5 completely changed the way it saves images — something that only became obvious when testing on an actual Divi website. Reading documentation alone would not have caught it.", ""),
                numItem("num3", " Different browsers behave differently. A small HTML mistake made the page look perfectly fine in Chrome but completely broken in Firefox. Never assume it works just because it looks right in one browser.", ""),
                numItem("num3", " Instant filtering feels better than page reloads. When all the data is already on screen, using JavaScript to show and hide rows — instead of reloading the page — makes the experience feel much faster and smoother.", ""),
                numItem("num3", " Only flag things that actually matter. Showing quality warnings for unused files is just noise. If a file is not on the site, there is nothing to fix. Keeping the audit list focused on files that are actually being used made it far more useful.", ""),
                numItem("num3", " Small naming mistakes can cause silent failures. A one-word difference in how a plugin was identified internally caused Beaver Builder images to not be detected at all — with no error message to explain why. Exact names matter.", ""),
                spacer(),
            ]
        }
    ]
});

Packer.toBuffer(doc).then(buffer => {
    fs.writeFileSync("C:\\media-usage-tracker\\MUT-Build-Journey.docx", buffer);
    console.log("Done: C:\\media-usage-tracker\\MUT-Build-Journey.docx");
}).catch(err => console.error(err));
