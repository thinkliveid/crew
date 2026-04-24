# Crew

A PHP CLI tool for managing AI agent skills, sub-agents, and team templates across multiple IDE coding assistants.

Crew discovers which AI agents are installed on your system, downloads resources from GitHub repositories, syncs local definitions, and writes configurations to the correct agent-specific directories — all from a single command.

## Requirements

- PHP 8.3+
- Composer

## Installation

```bash
composer require thinkliveid/crew
```

## Quick Start

```bash
# One-shot setup: install skills, sub-agents, and team templates together
crew init

# Refresh everything from saved config in one pass
crew sync

# Or run a single resource type
crew install:skill

# Create a new skill from scratch
crew new:skill

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

When you run `crew install:skill`, only detected agents are shown for selection. Resources are written to the correct paths for each selected agent.

## Commands

Crew organises commands into four groups across three resource types, plus top-level `init` and `sync` commands that run all three installers/updaters in one pass:

| | Skills | Sub-agents | Teams |
|---|---|---|---|
| **new** | `new:skill` | `new:subagent` | `new:team` |
| **install** | `install:skill` | `install:subagent` | `install:team` |
| **add** | `add:skill` | `add:subagent` | `add:team` |
| **update** | `update:skill` | `update:subagent` | `update:team` |

### `init` — Install everything at once

Runs `install:skill`, `install:subagent`, and `install:team` in sequence. The first step performs agent detection and saves the selection to `crew.json`; the other two reuse that selection, so you're only prompted for agents once.

```bash
crew init

# Non-interactive: uses saved/auto-detected agents, skips all prompts
crew init --no-interaction
```

### `sync` — Update everything at once

Runs `update:skill`, `update:subagent`, and `update:team` in sequence. Each step reuses the agents saved in `crew.json` and refreshes local resources plus any configured GitHub sources.

```bash
crew sync
```

### `new:*` — Scaffold a new resource locally

Create a new skill, sub-agent, or team definition from scratch with an interactive prompt.

```bash
# Interactive — prompts for name and description
crew new:skill
crew new:subagent
crew new:team

# Provide the name upfront to skip the name prompt
crew new:skill my-skill
crew new:subagent my-agent
crew new:team my-team
```

Generated files:

| Command | Output |
|---------|--------|
| `new:skill` | `.ai/skills/{name}/SKILL.md` |
| `new:subagent` | `.ai/agents/{name}.md` |
| `new:team` | `.ai/teams/{name}/TEAM.md` |

Names must be lowercase alphanumeric with hyphens (1–64 chars, no leading/trailing/consecutive hyphens). `new:subagent` also prompts for a model choice (`sonnet`, `opus`, `haiku`, or `inherit`).

### `install:*` — Full setup flow

Runs the complete setup: detect agents, select them, sync local resources, and install from GitHub.

```bash
crew install:skill
crew install:subagent
crew install:team
```

Non-interactive mode (uses auto-detected agents, skips prompts):

```bash
crew install:skill --no-interaction
```

### `add:*` — Add from a GitHub repository

Discover and install resources from a GitHub repository. The repository is saved to `crew.json` for future installs and updates.

```bash
# Interactive — prompts for repository if not provided
crew add:skill
crew add:subagent
crew add:team

# Direct — provide the repository
crew add:skill owner/repo
crew add:subagent owner/repo
crew add:team owner/repo

# Full GitHub URL with branch and subdirectory
crew add:skill https://github.com/owner/repo/tree/main/path/to/skills
```

### `update:*` — Update to the latest version

Re-runs the install flow in non-interactive mode to refresh all local and GitHub resources.

```bash
crew update:skill
crew update:subagent
crew update:team
```

## Configuration

Crew stores its configuration in `crew.json` at the project root:

```json
{
    "agents": ["claude_code", "junie"],
    "skills": ["owner/repo"],
    "subagents": ["owner/repo"],
    "teams": ["owner/repo"]
}
```

| Key | Type | Description |
|-----|------|-------------|
| `agents` | `string[]` | Selected agent identifiers |
| `skills` | `string[]` | GitHub repositories to install skills from |
| `subagents` | `string[]` | GitHub repositories to install sub-agents from |
| `teams` | `string[]` | GitHub repositories to install team templates from |
| `guidelines` | `bool` | Whether guideline writing is enabled |
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

## Project Structure

### Local Resources

Place resources in your project's `.ai/` directory:

```
.ai/
  skills/
    my-skill/
      SKILL.md
      other-files...
  agents/
    my-agent.md
  teams/
    my-team/
      TEAM.md
```

Running `crew install:skill` copies skills from `.ai/skills/` to each selected agent's skill directory (e.g., `.claude/skills/my-skill/`, `.junie/skills/my-skill/`). The same pattern applies to sub-agents and teams.

### Remote Resources (GitHub)

Crew discovers resources in GitHub repositories by traversing the repo tree and looking for marker files (`SKILL.md`, agent markdown files, `TEAM.md`).

## Architecture

Crew is built with a contract-driven architecture. Each agent implements interfaces that define its capabilities:

- **SupportsSkills** — agent can receive skill files
- **SupportsSubAgents** — agent can receive sub-agent definitions
- **SupportsTeams** — agent can receive team templates
- **SupportsGuidelines** — agent can receive guideline files
- **SupportsMcp** — agent can receive MCP server configurations

The detection system uses a strategy pattern to discover agents:

- **DirectoryDetectionStrategy** — checks for directories/paths (supports glob patterns)
- **FileDetectionStrategy** — checks for specific files in the project
- **CommandDetectionStrategy** — runs shell commands to detect installed tools
- **CompositeDetectionStrategy** — combines multiple strategies with OR logic

## License

MIT
