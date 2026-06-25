const fs = require('fs');
const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  Header, Footer, AlignmentType, LevelFormat, HeadingLevel,
  BorderStyle, WidthType, ShadingType, PageNumber, PageBreak,
  TableOfContents
} = require('docx');

// A4 content width with 1" margins: 11906 - 2880 = 9026 DXA
const CONTENT_WIDTH = 9026;
const BLUE = "2E75B6";
const GREEN = "00703C";
const GREY = "757575";
const LIGHT_BLUE = "D5E8F4";
const LIGHT_GREEN = "E2F0E8";
const LIGHT_GREY = "F5F5F5";

const cellBorder = { style: BorderStyle.SINGLE, size: 1, color: "CCCCCC" };
const cellBorders = { top: cellBorder, bottom: cellBorder, left: cellBorder, right: cellBorder };

function headingCell(text, width) {
  return new TableCell({
    borders: cellBorders,
    width: { size: width, type: WidthType.DXA },
    shading: { fill: BLUE, type: ShadingType.CLEAR },
    margins: { top: 100, bottom: 100, left: 150, right: 150 },
    children: [new Paragraph({
      children: [new TextRun({ text, bold: true, color: "FFFFFF", font: "Arial", size: 20 })]
    })]
  });
}

function dataCell(text, width, color, bold) {
  return new TableCell({
    borders: cellBorders,
    width: { size: width, type: WidthType.DXA },
    shading: { fill: color || "FFFFFF", type: ShadingType.CLEAR },
    margins: { top: 80, bottom: 80, left: 150, right: 150 },
    children: [new Paragraph({
      children: [new TextRun({ text, font: "Arial", size: 20, bold: bold || false })]
    })]
  });
}

function statusCell(text, width) {
  let color = "FFFFFF";
  let textColor = "000000";
  if (text === "Confirmed Working" || text === "Live" || text === "Completed") {
    color = LIGHT_GREEN;
    textColor = GREEN;
  } else if (text === "Planned") {
    color = LIGHT_GREY;
    textColor = GREY;
  } else if (text.startsWith("Live")) {
    color = LIGHT_GREEN;
    textColor = GREEN;
  }
  return new TableCell({
    borders: cellBorders,
    width: { size: width, type: WidthType.DXA },
    shading: { fill: color, type: ShadingType.CLEAR },
    margins: { top: 80, bottom: 80, left: 150, right: 150 },
    children: [new Paragraph({
      children: [new TextRun({ text, font: "Arial", size: 20, color: textColor, bold: true })]
    })]
  });
}

function bodyPara(text, spacing) {
  return new Paragraph({
    spacing: { after: spacing || 160 },
    children: [new TextRun({ text, font: "Arial", size: 22 })]
  });
}

function spacer() {
  return new Paragraph({ children: [new TextRun("")], spacing: { after: 120 } });
}

// Features table
const featuresTable = new Table({
  width: { size: CONTENT_WIDTH, type: WidthType.DXA },
  columnWidths: [2700, 6326],
  rows: [
    new TableRow({
      tableHeader: true,
      children: [headingCell("Feature", 2700), headingCell("Description", 6326)]
    }),
    ...[
      ["Media Scan", "Scans your entire media library and records where every file is used — across pages, posts, products, and plugin-specific content."],
      ["Usage Details", "For each file, shows exactly which pages or posts are using it, what type of usage it is (featured image, gallery, product image, etc.), and which plugin placed it there."],
      ["Search and Filter", "Find files quickly by filename, usage status, file type, size, upload date, or which plugin is using them."],
      ["AI Natural Language Search", "Type a plain English query like \"unused images from last month\" or \"images used by Elementor\" and MUT will set the filters automatically."],
      ["Quality Audit", "Automatically checks all in-use files for missing alt text, missing captions, missing descriptions, and oversized file sizes."],
      ["AI Alt Text Generation", "Connects to an AI service to generate descriptive alt text for images that are missing it — reviewed and saved with one click."],
      ["Bulk Review", "Review all media files in one place, flag files for review, archive them, or safely move unused ones to trash."],
      ["Safe Delete", "Before any file is deleted, MUT runs a safety check to confirm it is not being used anywhere. Files go to Trash first — not permanently deleted."],
      ["Storage Optimization", "Highlights files that are larger than necessary and flags them for compression or replacement."],
      ["Duplicate Finder", "Identifies files that appear to be copies of each other, helping reduce unnecessary clutter."],
      ["Reports and Exports", "Export a full media library report as a CSV spreadsheet, Excel file, or PDF at any time."],
    ].map((row, i) => new TableRow({
      children: [
        dataCell(row[0], 2700, i % 2 === 0 ? "F8FBFF" : "FFFFFF", true),
        dataCell(row[1], 6326, i % 2 === 0 ? "F8FBFF" : "FFFFFF"),
      ]
    }))
  ]
});

// Plugins table
const pluginsTable = new Table({
  width: { size: CONTENT_WIDTH, type: WidthType.DXA },
  columnWidths: [2200, 4826, 2000],
  rows: [
    new TableRow({
      tableHeader: true,
      children: [headingCell("Plugin / Theme", 2200), headingCell("What It Detects", 4826), headingCell("Status", 2000)]
    }),
    ...[
      ["Elementor", "Images across all widget types — single image, gallery, carousel, slider, background, and more", "Confirmed Working"],
      ["JetEngine", "Images stored in custom content type fields", "Confirmed Working"],
      ["JetPopup", "Static background and overlay images on popups", "Confirmed Working"],
      ["WooCommerce", "Product images, gallery photos, variation images, and category images", "Confirmed Working"],
      ["ACF (Advanced Custom Fields)", "Images in custom image fields, including which field the image came from", "Confirmed Working"],
      ["Yoast SEO", "Social sharing image (Open Graph)", "Confirmed Working"],
      ["Gravity Forms", "Images embedded in form fields and confirmation messages", "Confirmed Working"],
      ["Avada / Fusion Builder", "Images inside Avada page builder layouts", "Confirmed Working"],
      ["Beaver Builder", "Images in photo modules and gallery layouts", "Confirmed Working"],
      ["WPBakery Page Builder", "Images in single image and gallery blocks", "Confirmed Working"],
      ["Divi", "Supports both Divi 4 (classic) and Divi 5 (new format)", "Confirmed Working"],
      ["Astra", "Site logo and theme customizer images", "Confirmed Working"],
      ["wpDataTables", "Images in manual data tables", "Planned"],
    ].map((row, i) => new TableRow({
      children: [
        dataCell(row[0], 2200, i % 2 === 0 ? "F8FBFF" : "FFFFFF", true),
        dataCell(row[1], 4826, i % 2 === 0 ? "F8FBFF" : "FFFFFF"),
        statusCell(row[2], 2000),
      ]
    }))
  ]
});

// Deployment status table
const deployTable = new Table({
  width: { size: CONTENT_WIDTH, type: WidthType.DXA },
  columnWidths: [5013, 4013],
  rows: [
    new TableRow({
      tableHeader: true,
      children: [headingCell("Area", 5013), headingCell("Status", 4013)]
    }),
    ...[
      ["Plugin Installation", "Live"],
      ["Initial Scan", "Completed"],
      ["Elementor Detection", "Confirmed Working"],
      ["WooCommerce Detection", "Confirmed Working"],
      ["ACF Detection", "Confirmed Working"],
      ["Yoast SEO Detection", "Confirmed Working"],
      ["Gravity Forms Detection", "Confirmed Working"],
      ["Avada Detection", "Confirmed Working"],
      ["Beaver Builder Detection", "Confirmed Working"],
      ["Divi Detection (v4 + v5)", "Confirmed Working"],
      ["WPBakery Detection", "Confirmed Working"],
      ["JetEngine Detection", "Confirmed Working"],
      ["JetPopup Detection", "Confirmed Working"],
      ["Astra Detection", "Confirmed Working"],
      ["Quality Audit", "Live"],
      ["AI Alt Text Generation", "Live (requires API key)"],
      ["Bulk Review and Safe Delete", "Live"],
      ["Search and Filter", "Live"],
      ["AI Natural Language Search", "Live"],
      ["Storage Optimization", "Live"],
      ["Reports and Exports", "Live"],
    ].map((row, i) => new TableRow({
      children: [
        dataCell(row[0], 5013, i % 2 === 0 ? "F8FBFF" : "FFFFFF", true),
        statusCell(row[1], 4013),
      ]
    }))
  ]
});

const doc = new Document({
  numbering: {
    config: [
      {
        reference: "bullets",
        levels: [{
          level: 0, format: LevelFormat.BULLET, text: "•", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 720, hanging: 360 } } }
        }]
      },
      {
        reference: "numbers",
        levels: [{
          level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 720, hanging: 360 } } }
        }]
      },
    ]
  },
  styles: {
    default: {
      document: { run: { font: "Arial", size: 22 } }
    },
    paragraphStyles: [
      {
        id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 36, bold: true, font: "Arial", color: BLUE },
        paragraph: { spacing: { before: 320, after: 200 }, outlineLevel: 0 }
      },
      {
        id: "Heading2", name: "Heading 2", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 28, bold: true, font: "Arial", color: BLUE },
        paragraph: { spacing: { before: 240, after: 160 }, outlineLevel: 1 }
      },
    ]
  },
  sections: [
    // ── SECTION 1: Cover page (no footer) ──
    {
      properties: {
        page: {
          size: { width: 11906, height: 16838 },
          margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 }
        }
      },
      children: [
        // Big vertical space
        new Paragraph({ spacing: { after: 2880 }, children: [new TextRun("")] }),
        new Paragraph({
          alignment: AlignmentType.CENTER,
          spacing: { after: 200 },
          children: [new TextRun({ text: "Media Usage Tracker", font: "Arial", size: 64, bold: true, color: BLUE })]
        }),
        new Paragraph({
          alignment: AlignmentType.CENTER,
          spacing: { after: 120 },
          children: [new TextRun({ text: "(MUT)", font: "Arial", size: 36, color: BLUE })]
        }),
        new Paragraph({
          alignment: AlignmentType.CENTER,
          spacing: { after: 600 },
          border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: BLUE, space: 1 } },
          children: [new TextRun({ text: "Live Deployment Report", font: "Arial", size: 40, bold: true, color: "444444" })]
        }),
        spacer(),
        new Paragraph({
          alignment: AlignmentType.CENTER,
          spacing: { after: 120 },
          children: [new TextRun({ text: "June 19, 2026", font: "Arial", size: 24, color: "666666" })]
        }),
        new Paragraph({
          alignment: AlignmentType.CENTER,
          spacing: { after: 120 },
          children: [new TextRun({ text: "Trusted Digital Agency", font: "Arial", size: 24, bold: true })]
        }),
        new Paragraph({
          alignment: AlignmentType.CENTER,
          spacing: { after: 120 },
          children: [new TextRun({ text: "support@trusteddigitalagency.com", font: "Arial", size: 22, color: BLUE })]
        }),
      ]
    },
    // ── SECTION 2: TOC + body (with footer) ──
    {
      properties: {
        page: {
          size: { width: 11906, height: 16838 },
          margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 }
        }
      },
      footers: {
        default: new Footer({
          children: [new Paragraph({
            border: { top: { style: BorderStyle.SINGLE, size: 4, color: "CCCCCC", space: 1 } },
            tabStops: [{ type: "right", position: CONTENT_WIDTH }],
            children: [
              new TextRun({ text: "Media Usage Tracker — Live Deployment Report", font: "Arial", size: 18, color: "888888" }),
              new TextRun({ text: "\t", font: "Arial", size: 18 }),
              new TextRun({ text: "Page ", font: "Arial", size: 18, color: "888888" }),
              new TextRun({ children: [PageNumber.CURRENT], font: "Arial", size: 18, color: "888888" }),
              new TextRun({ text: " of ", font: "Arial", size: 18, color: "888888" }),
              new TextRun({ children: [PageNumber.TOTAL_PAGES], font: "Arial", size: 18, color: "888888" }),
            ]
          })]
        })
      },
      children: [
        // TOC
        new Paragraph({ heading: HeadingLevel.HEADING_1, children: [new TextRun({ text: "Contents", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        new TableOfContents("Table of Contents", { hyperlink: true, headingStyleRange: "1-2" }),
        new Paragraph({ children: [new PageBreak()] }),

        // SECTION 1: Introduction
        new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: false, children: [new TextRun({ text: "What is Media Usage Tracker?", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        bodyPara("Every WordPress website accumulates media files over time — images, PDFs, videos, and more. As websites grow and content changes, it becomes difficult to know which files are still being used and which are just sitting in the library taking up space."),
        bodyPara("Media Usage Tracker (MUT) was built to solve exactly that problem. It is a WordPress admin plugin that scans your entire media library and tells you, clearly and accurately, where each file is being used — or confirms that it is not being used anywhere at all."),
        bodyPara("Beyond simply tracking usage, MUT also helps with the ongoing quality and maintenance of your media library. It flags images with missing alt text, identifies oversized files that may be slowing your site down, helps you safely remove unused files, and can even generate descriptive alt text automatically using AI."),
        bodyPara("MUT is designed to be practical and straightforward. It works quietly inside your WordPress dashboard, requires no technical knowledge to use, and supports all major page builders and plugins out of the box."),
        spacer(),

        // SECTION 2: Features
        new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: "Key Features", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        featuresTable,
        spacer(),

        // SECTION 3: Plugin Support
        new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: "Supported Plugins and Themes", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        bodyPara("MUT includes built-in detectors for all major WordPress page builders and plugins. Each detector only runs when the relevant plugin or theme is actually installed and active on the site."),
        spacer(),
        pluginsTable,
        spacer(),

        // SECTION 4: Deployment Status
        new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: "Deployment Status — June 19, 2026", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        bodyPara("Media Usage Tracker has been successfully deployed and is now live on the development site. The plugin has been installed, activated, and fully tested across all major features and supported plugins."),
        spacer(),
        deployTable,
        spacer(),

        // SECTION 5: Notes
        new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: "Notes and Known Behaviour", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        ...[
          ["JetPopup dynamic images", "Images that JetPopup pulls dynamically from JetEngine fields at runtime are tracked under JetEngine, not JetPopup. This is expected behaviour. A note is shown in the interface when the JetPopup filter returns no results."],
          ["WooCommerce placeholder", "The default WooCommerce product placeholder image is a built-in file bundled with the WooCommerce plugin. It is not stored in the WordPress media library and therefore does not appear in MUT's scan results. This is expected behaviour."],
          ["Divi 5 support", "Divi 5 stores page content in a completely different format compared to older versions. MUT's Divi detector has been fully rewritten to support the new format. Both Divi 4 and Divi 5 are supported simultaneously."],
          ["Quality Audit counts", "The Quality Audit cards (Missing Alt Text, Missing Caption, Missing Description) only count files that are actively in use on the site. Unused files are excluded because there is no benefit to fixing metadata on files that are not visible to visitors."],
          ["Safe deletion", "MUT never permanently deletes files in one step. All deletions go to the WordPress Trash first, giving you the opportunity to restore a file if it was removed by mistake."],
          ["wpDataTables", "Detection for wpDataTables is planned for a future update. All other 13 detectors are currently active."],
        ].map(([label, desc]) => new Paragraph({
          numbering: { reference: "bullets", level: 0 },
          spacing: { after: 160 },
          children: [
            new TextRun({ text: label + " — ", font: "Arial", size: 22, bold: true }),
            new TextRun({ text: desc, font: "Arial", size: 22 }),
          ]
        })),
        spacer(),

        // SECTION 6: Next Steps
        new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: "Recommended Next Steps", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        ...[
          ["Run a full scan", "Go to the MUT Dashboard and click Start New Scan to build the complete usage map for your media library."],
          ["Review the Quality Audit", "Check the Media Quality Audit page and address any high-priority issues, particularly missing alt text on in-use images."],
          ["Use AI Alt Text Generation", "If an AI API key has been configured, use the Generate Alt Text with AI button to automatically create descriptions for flagged images."],
          ["Clean up unused files", "Navigate to the Unused pill on the Media Usage page or use the Search and Filter page to review unused files and safely remove the ones no longer needed."],
          ["Export a report", "Use the Reports section to export a full snapshot of your media library for your records or to share with a client."],
        ].map(([label, desc]) => new Paragraph({
          numbering: { reference: "numbers", level: 0 },
          spacing: { after: 160 },
          children: [
            new TextRun({ text: label + " — ", font: "Arial", size: 22, bold: true }),
            new TextRun({ text: desc, font: "Arial", size: 22 }),
          ]
        })),
        spacer(),

        // SECTION 7: Contact
        new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: "Support and Feedback", font: "Arial", size: 36, bold: true, color: BLUE })] }),
        bodyPara("MUT is actively maintained and improved. If you encounter a plugin or theme that is not yet supported, or if you have feedback on how the plugin can be improved, please get in touch."),
        new Paragraph({
          spacing: { after: 120 },
          children: [
            new TextRun({ text: "Email: ", font: "Arial", size: 22, bold: true }),
            new TextRun({ text: "support@trusteddigitalagency.com", font: "Arial", size: 22, color: BLUE }),
          ]
        }),
        bodyPara("Your input directly shapes future updates and helps expand compatibility with more plugins and themes."),
      ]
    }
  ]
});

Packer.toBuffer(doc).then(buffer => {
  fs.writeFileSync("C:\\media-usage-tracker\\MUT-Live-Report.docx", buffer);
  console.log("Done: MUT-Live-Report.docx created");
}).catch(err => {
  console.error("Error:", err);
  process.exit(1);
});
