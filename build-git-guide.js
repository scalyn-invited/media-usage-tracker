const fs = require('fs');
const {
  Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
  Footer, AlignmentType, LevelFormat, HeadingLevel,
  BorderStyle, WidthType, ShadingType, PageNumber, PageBreak
} = require('docx');

const BLUE = "2E75B6";
const GREEN = "00703C";
const CONTENT_WIDTH = 9026;

const cellBorder = { style: BorderStyle.SINGLE, size: 1, color: "CCCCCC" };
const borders = { top: cellBorder, bottom: cellBorder, left: cellBorder, right: cellBorder };
const cellMargins = { top: 80, bottom: 80, left: 120, right: 120 };

function hCell(text, width) {
  return new TableCell({
    borders, width: { size: width, type: WidthType.DXA },
    shading: { fill: BLUE, type: ShadingType.CLEAR },
    margins: cellMargins,
    children: [new Paragraph({ children: [new TextRun({ text, bold: true, color: "FFFFFF", font: "Arial", size: 20 })] })]
  });
}

function dCell(text, width, opts = {}) {
  const runs = [];
  if (opts.mono) {
    runs.push(new TextRun({ text, font: "Consolas", size: 20, color: opts.color || "000000", bold: opts.bold || false }));
  } else {
    runs.push(new TextRun({ text, font: "Arial", size: 20, color: opts.color || "000000", bold: opts.bold || false }));
  }
  return new TableCell({
    borders, width: { size: width, type: WidthType.DXA },
    shading: { fill: opts.fill || "FFFFFF", type: ShadingType.CLEAR },
    margins: cellMargins,
    children: [new Paragraph({ children: runs })]
  });
}

function iconCell(icon, width) {
  const colorMap = { "EVERYDAY": GREEN, "SETUP": "2271B1", "CAUTION": "D63638", "USEFUL": "8C5FCE", "VERSION": "BA7517" };
  const color = colorMap[icon] || "646970";
  return new TableCell({
    borders, width: { size: width, type: WidthType.DXA },
    shading: { fill: "F8F8F8", type: ShadingType.CLEAR },
    margins: cellMargins,
    children: [new Paragraph({
      alignment: AlignmentType.CENTER,
      children: [new TextRun({ text: icon, font: "Arial", size: 16, bold: true, color })]
    })]
  });
}

function sectionHeading(text) {
  return new Paragraph({
    heading: HeadingLevel.HEADING_1,
    children: [new TextRun({ text, font: "Arial", size: 32, bold: true, color: BLUE })]
  });
}

function bodyText(text) {
  return new Paragraph({ spacing: { after: 120 }, children: [new TextRun({ text, font: "Arial", size: 22 })] });
}

function spacer() {
  return new Paragraph({ spacing: { after: 100 }, children: [new TextRun("")] });
}

const commands = [
  // SETUP
  ["SETUP", "git init", "Initialize a new Git repository in the current folder", "Run once when starting a new project"],
  ["SETUP", "git clone <url>", "Download a repository from GitHub to your computer", "Use the HTTPS URL from GitHub"],
  ["SETUP", "git remote add origin <url>", "Connect your local repo to a GitHub repository", "Run once after git init"],
  ["SETUP", 'git config --global user.name "Your Name"', "Set your name for commits", "Run once per computer"],
  ["SETUP", 'git config --global user.email "you@email.com"', "Set your email for commits", "Run once per computer"],

  // EVERYDAY
  ["EVERYDAY", "git status", "See which files have changed since the last commit", "Run this often to check your state"],
  ["EVERYDAY", "git add -A", "Stage all changed files for the next commit", "Includes new, modified, and deleted files"],
  ["EVERYDAY", 'git commit -m "message"', "Save staged changes with a description", "Write clear, short messages"],
  ["EVERYDAY", "git push", "Upload your commits to GitHub", "Run after every commit to keep GitHub in sync"],
  ["EVERYDAY", "git pull", "Download the latest changes from GitHub", "Run before starting work if others contribute"],

  // VERSION
  ["VERSION", "git log --oneline", "See a compact list of all commits", "Shows commit hash + message"],
  ["VERSION", "git diff", "See what changed in files that haven't been staged yet", "Shows line-by-line changes"],
  ["VERSION", "git diff --staged", "See what's staged and ready to commit", "Run before committing to review"],

  // USEFUL
  ["USEFUL", "git rm <file>", "Delete a file and tell Git about it in one step", "Faster than manual delete + git add"],
  ["USEFUL", "git rm -r --cached <folder>", "Remove a folder from Git but keep it locally", "Used to untrack node_modules etc."],
  ["USEFUL", "git checkout -- <file>", "Discard changes to a file (restore last commit)", "Cannot be undone"],
  ["USEFUL", 'git stash', "Temporarily save uncommitted changes", "Use git stash pop to restore them"],

  // CAUTION
  ["CAUTION", "git push --force", "Force push — overwrites remote history", "Only use when you rewrote history (filter-branch)"],
  ["CAUTION", "git reset --hard HEAD", "Discard ALL uncommitted changes", "Cannot be undone — use with care"],
  ["CAUTION", "git filter-branch", "Rewrite commit history (remove secrets, etc.)", "Requires force push after"],
];

const workflowSteps = [
  ["1", "Make your code changes", "Edit PHP, CSS, JS files as needed"],
  ["2", "git add -A", "Stage all changes"],
  ["3", 'git commit -m "Add new feature X"', "Commit with a clear message"],
  ["4", "git push", "Push to GitHub"],
  ["5", "Update version number (if releasing)", "Edit media-usage-tracker.php lines 6 and 21"],
  ["6", 'git add -A && git commit -m "Bump version to 1.1.0"', "Commit the version bump"],
  ["7", "git push", "Push the release"],
];

const versionExamples = [
  ["Bug fix (small)", "1.0.0 -> 1.0.1", "Fixed a CSS bug, typo, small patch"],
  ["New feature", "1.0.0 -> 1.1.0", "Added Media by Page, Replace Image, etc."],
  ["Major release", "1.0.0 -> 2.0.0", "Complete redesign, breaking changes"],
];

const doc = new Document({
  styles: {
    default: { document: { run: { font: "Arial", size: 22 } } },
    paragraphStyles: [
      { id: "Heading1", name: "Heading 1", basedOn: "Normal", next: "Normal", quickFormat: true,
        run: { size: 32, bold: true, font: "Arial", color: BLUE },
        paragraph: { spacing: { before: 300, after: 200 }, outlineLevel: 0 } },
    ]
  },
  sections: [{
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
            new TextRun({ text: "MUT Git Commands Guide", font: "Arial", size: 18, color: "888888" }),
            new TextRun({ text: "\t", font: "Arial", size: 18 }),
            new TextRun({ text: "Page ", font: "Arial", size: 18, color: "888888" }),
            new TextRun({ children: [PageNumber.CURRENT], font: "Arial", size: 18, color: "888888" }),
          ]
        })]
      })
    },
    children: [
      // Title
      new Paragraph({
        spacing: { after: 100 },
        children: [new TextRun({ text: "Media Usage Tracker", font: "Arial", size: 48, bold: true, color: BLUE })]
      }),
      new Paragraph({
        spacing: { after: 60 },
        border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: BLUE, space: 1 } },
        children: [new TextRun({ text: "Git Commands Guide", font: "Arial", size: 36, bold: true, color: "444444" })]
      }),
      new Paragraph({
        spacing: { after: 200 },
        children: [
          new TextRun({ text: "Repository: ", font: "Arial", size: 22, color: "666666" }),
          new TextRun({ text: "https://github.com/scalyn-invited/media-usage-tracker", font: "Arial", size: 22, color: BLUE }),
        ]
      }),
      spacer(),

      // Legend
      new Paragraph({
        spacing: { after: 120 },
        children: [new TextRun({ text: "Indicator Legend", font: "Arial", size: 24, bold: true })]
      }),
      new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: [1500, 3000, 4526],
        rows: [
          new TableRow({ children: [hCell("Indicator", 1500), hCell("Meaning", 3000), hCell("When to Use", 4526)] }),
          new TableRow({ children: [iconCell("SETUP", 1500), dCell("Initial setup commands", 3000), dCell("Run once when setting up the project or a new computer", 4526)] }),
          new TableRow({ children: [iconCell("EVERYDAY", 1500), dCell("Daily workflow commands", 3000), dCell("Run these every time you make changes", 4526, { fill: "F0FFF0" })] }),
          new TableRow({ children: [iconCell("VERSION", 1500), dCell("Version and history commands", 3000), dCell("Use to review changes and commit history", 4526)] }),
          new TableRow({ children: [iconCell("USEFUL", 1500), dCell("Handy utility commands", 3000), dCell("Helpful but not needed every day", 4526)] }),
          new TableRow({ children: [iconCell("CAUTION", 1500), dCell("Dangerous commands", 3000), dCell("Can cause data loss — use carefully", 4526, { fill: "FFF0F0" })] }),
        ]
      }),
      spacer(),

      // Commands table
      new Paragraph({ children: [new PageBreak()] }),
      sectionHeading("All Git Commands"),
      spacer(),
      new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: [1200, 3200, 2800, 1826],
        rows: [
          new TableRow({ children: [
            hCell("Type", 1200), hCell("Command", 3200), hCell("What It Does", 2800), hCell("Notes", 1826)
          ] }),
          ...commands.map((row, i) => new TableRow({
            children: [
              iconCell(row[0], 1200),
              dCell(row[1], 3200, { mono: true }),
              dCell(row[2], 2800, { fill: i % 2 === 0 ? "F8FBFF" : "FFFFFF" }),
              dCell(row[3], 1826, { fill: i % 2 === 0 ? "F8FBFF" : "FFFFFF", color: "666666" }),
            ]
          }))
        ]
      }),
      spacer(),

      // Everyday workflow
      new Paragraph({ children: [new PageBreak()] }),
      sectionHeading("Your Everyday Workflow"),
      bodyText("Follow these steps every time you make changes to the plugin:"),
      spacer(),
      new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: [600, 3500, 4926],
        rows: [
          new TableRow({ children: [hCell("Step", 600), hCell("Command / Action", 3500), hCell("Explanation", 4926)] }),
          ...workflowSteps.map((row, i) => new TableRow({
            children: [
              dCell(row[0], 600, { bold: true, fill: "F0F6FF" }),
              dCell(row[1], 3500, { mono: true }),
              dCell(row[2], 4926, { fill: i % 2 === 0 ? "F8FBFF" : "FFFFFF" }),
            ]
          }))
        ]
      }),
      spacer(),

      // Version numbering
      sectionHeading("Version Numbering"),
      bodyText("Update the version in media-usage-tracker.php in two places:"),
      bodyText("Line 6:   * Version:  1.0.0"),
      bodyText("Line 21:  define( 'MUT_VERSION', '1.0.0' );"),
      spacer(),
      new Table({
        width: { size: CONTENT_WIDTH, type: WidthType.DXA },
        columnWidths: [2500, 2500, 4026],
        rows: [
          new TableRow({ children: [hCell("Change Type", 2500), hCell("Version Example", 2500), hCell("When to Use", 4026)] }),
          ...versionExamples.map((row, i) => new TableRow({
            children: [
              dCell(row[0], 2500, { bold: true }),
              dCell(row[1], 2500, { mono: true, fill: i % 2 === 0 ? "F8FBFF" : "FFFFFF" }),
              dCell(row[2], 4026, { fill: i % 2 === 0 ? "F8FBFF" : "FFFFFF" }),
            ]
          }))
        ]
      }),
      spacer(),

      // Quick reference
      new Paragraph({ children: [new PageBreak()] }),
      sectionHeading("Quick Reference Card"),
      bodyText("Copy-paste these commands. Replace the message in quotes with your own."),
      spacer(),
      new Paragraph({ spacing: { after: 80 }, children: [new TextRun({ text: "Push changes to GitHub:", font: "Arial", size: 22, bold: true })] }),
      new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: 'git add -A', font: "Consolas", size: 22, color: BLUE })] }),
      new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: 'git commit -m "Your message here"', font: "Consolas", size: 22, color: BLUE })] }),
      new Paragraph({ spacing: { after: 200 }, children: [new TextRun({ text: 'git push', font: "Consolas", size: 22, color: BLUE })] }),

      new Paragraph({ spacing: { after: 80 }, children: [new TextRun({ text: "Bump version and release:", font: "Arial", size: 22, bold: true })] }),
      new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: '1. Edit version in media-usage-tracker.php (lines 6 and 21)', font: "Arial", size: 22 })] }),
      new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: 'git add -A', font: "Consolas", size: 22, color: BLUE })] }),
      new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: 'git commit -m "Bump version to X.Y.Z"', font: "Consolas", size: 22, color: BLUE })] }),
      new Paragraph({ spacing: { after: 200 }, children: [new TextRun({ text: 'git push', font: "Consolas", size: 22, color: BLUE })] }),

      new Paragraph({ spacing: { after: 80 }, children: [new TextRun({ text: "Check what's changed:", font: "Arial", size: 22, bold: true })] }),
      new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: 'git status', font: "Consolas", size: 22, color: BLUE })] }),
      new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: 'git log --oneline', font: "Consolas", size: 22, color: BLUE })] }),
      new Paragraph({ spacing: { after: 200 }, children: [new TextRun({ text: 'git diff', font: "Consolas", size: 22, color: BLUE })] }),

      new Paragraph({ spacing: { after: 80 }, children: [new TextRun({ text: "Download latest from GitHub:", font: "Arial", size: 22, bold: true })] }),
      new Paragraph({ spacing: { after: 200 }, children: [new TextRun({ text: 'git pull', font: "Consolas", size: 22, color: BLUE })] }),

      // Footer note
      spacer(),
      new Paragraph({
        border: { top: { style: BorderStyle.SINGLE, size: 4, color: "CCCCCC", space: 1 } },
        spacing: { before: 200 },
        children: [new TextRun({ text: "Prepared for Media Usage Tracker v1.0.0 — Trusted Digital Agency", font: "Arial", size: 20, color: "888888" })]
      }),
    ]
  }]
});

Packer.toBuffer(doc).then(buffer => {
  fs.writeFileSync("C:\\media-usage-tracker\\MUT-Git-Commands-Guide.docx", buffer);
  console.log("Done: MUT-Git-Commands-Guide.docx created");
}).catch(err => { console.error("Error:", err); process.exit(1); });
