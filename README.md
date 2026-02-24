# Crew

A PHP CLI tool for managing AI agent skills, guidelines, and MCP (Model Context Protocol) configurations across multiple IDE coding assistants.

Crew discovers which AI agents are installed on your system, downloads skills from GitHub repositories, syncs local skills, and writes configurations to the correct agent-specific directories — all from a single command.

## Requirements

- PHP 8.3+
- Composer

## Installation

```bash
composer global require thinkliveid/crew
```

Or install as a project dependency:

```bash
composer require thinkliveid/crew
```

## Quick Start

```bash
# Detect agents, select them, sync local skills, and install GitHub skills
crew install:skill

# Add skills from a GitHub repository
crew add:skill owner/repo

# Update all configured skills to the latest version
crew update:skill
```

## Supported Agents

Crew auto-detects the following AI coding assistants on your system and in your project:

| Agent | Skills Path | MCP Config Path |
|-------|-------------|-----------------|
| Claude Code | `.claude/skills` | `.mcp.json` |
| Cursor | `.cursor/skills` | `.cursor/mcp.json` |
| GitHub Copilot | `.github/skills` | `.vscode/mcp.json` |
| Gemini CLI | `.agents/skills` | `.gemini/settings.json` |
| Junie | `.junie/skills` | `.junie/mcp/mcp.json` |
| Codex | `.agents/skills` | `.codex/config.toml` |
| OpenCode | `.agents/skills` | `opencode.json` |

When you run `crew install:skill`, only detected agents are shown for selection. Skills and configurations are written to the correct paths for each selected agent.

## Commands

### `install:skill`

The main command. Runs the full setup flow:

1. **Detect agents** — scans system and project for installed AI agents
2. **Select agents** — prompts to confirm which agents to configure (detected agents default to yes)
3. **Sync local skills** — copies skills from `.ai/skills/` to each agent's skill directory
4. **Install GitHub skills** — downloads skills from repositories listed in `crew.json`

```bash
crew install:skill
```

Non-interactive mode (uses auto-detected agents, skips prompts):

```bash
crew install:skill --no-interaction
```

### `add:skill`

Add skills from a GitHub repository. Crew discovers skills by looking for directories containing a `SKILL.md` file.

```bash
# Interactive — prompts for repository if not provided
crew add:skill

# Direct — provide the repository
crew add:skill owner/repo

# Full GitHub URL with branch and subdirectory
crew add:skill https://github.com/owner/repo/tree/main/path/to/skills
```

The repository is saved to `crew.json` so future installs and updates include it.

### `update:skill`

Re-runs `install:skill` in non-interactive mode to refresh all local and GitHub skills.

```bash
crew update:skill
```

## Configuration

Crew stores its configuration in `crew.json` at the project root:

```json
{
    "agents": ["claude_code", "junie"],
    "skills": ["owner/repo", "another/repo"]
}
```

| Key | Type | Description |
|-----|------|-------------|
| `agents` | `string[]` | Selected agent identifiers |
| `skills` | `string[]` | GitHub repositories to install skills from |
| `guidelines` | `bool` | Whether guidelines are enabled |
| `mcp` | `bool` | Whether MCP configuration is enabled |
| `github_token` | `string` | GitHub API token for private repositories |

### GitHub Authentication

For private repositories or to avoid API rate limits, set a GitHub token:

```json
{
    "github_token": "ghp_your_token_here"
}
```

Or use an environment variable:

```bash
export GITHUB_TOKEN=ghp_your_token_here
```

## Skills

### Local Skills

Place skills in your project's `.ai/skills/` directory. Each skill is a subdirectory containing a `SKILL.md` file:

```
.ai/skills/
  my-skill/
    SKILL.md
    other-files...
```

Running `crew install:skill` copies these to each selected agent's skill directory (e.g., `.claude/skills/my-skill/`, `.junie/skills/my-skill/`).

### Remote Skills (GitHub)

Crew discovers skills in GitHub repositories by traversing the repo tree and looking for `SKILL.md` files. Common search paths:

- `skills/`
- `.ai/skills/`
- `.cursor/skills/`
- `.claude/skills/`

## Architecture

Crew is built with a contract-driven architecture. Each agent implements interfaces that define its capabilities:

- **SupportsSkills** — agent can receive skill files
- **SupportsGuidelines** — agent can receive guideline files
- **SupportsMcp** — agent can receive MCP server configurations

The detection system uses a strategy pattern to discover agents:

- **DirectoryDetectionStrategy** — checks for directories/paths (supports glob patterns)
- **FileDetectionStrategy** — checks for specific files in the project
- **CommandDetectionStrategy** — runs shell commands to detect installed tools
- **CompositeDetectionStrategy** — combines multiple strategies with OR logic

## License

MIT
