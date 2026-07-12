
---
name: ci4-obsidian-doc-generator
description: Generates Obsidian-ready technical documentation and Mermaid flowcharts for CodeIgniter 4 projects using Codegraph index.
---

# Instructions for CI4 Document Generation

When the user asks to document a feature, controller, or workflow in their CodeIgniter 4 project, leverage the Codegraph/Sourcegraph MCP tool to explore the codebase, then generate an Obsidian-optimized markdown file.

## 1. CodeIgniter 4 Analysis Rules
- Locate the target **Controller** (`app/Controllers/`).
- Trace the **Route** (`app/Config/Routes.php`) to understand how requests enter.
- Identify the connected **Models** (`app/Models/`) and **Entities**.
- Check any applied **Filters** (`app/Config/Filters.php`) acting as middleware.

## 2. Output Format (Obsidian Markdown)
Every document must follow this frontmatter and structure so it links perfectly in Obsidian:

```markdown
---
tags: [ci4-doc, architecture, workflow]
date: {{CURRENT_DATE}}
links: [[Index]], [[Controllers]], [[Models]]
---

# Feature/Module Name

## 📌 Overview
Brief explanation of what this CI4 module does.

## 🗺️ Request Flowchart
Use Mermaid.js to chart the controller action. Follow the CI4 architecture (Filter -> Controller -> Model -> View/API).

```mermaid
graph TD
    A[Client Request] --> B[CI4 Filters/Middleware]
    B --> C[Controller: Method]
    C --> D[Validation]
    D -->|Success| E[Model / Database]
    D -->|Fail| F[Return Error Response]
    E --> G[Return JSON / Render View]