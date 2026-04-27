# Crew

A PHP CLI tool for managing AI agent skills, sub-agents, and slash commands across multiple IDE coding assistants.

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
# One-shot setup: install skills and sub-agents together
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

Crew organises commands into four groups across three resource types, plus top-level `init` and `sync` commands that run installers/updaters in one pass:

| | Skills | Sub-agents | Commands |
|---|---|---|---|
| **new** | `new:skill` | `new:subagent` | `new:command` |
| **install** | `install:skill` | `install:subagent` | `install:command` |
| **add** | `add:skill` | `add:subagent` | `add:command` |
| **update** | `update:skill` | `update:subagent` | `update:command` |

### `init` — Install everything at once

Runs `install:skill` and `install:subagent` in sequence. The first step performs agent detection and saves the selection to `crew.json`; the second reuses that selection, so you're only prompted for agents once.

```bash
crew init

# Non-interactive: uses saved/auto-detected agents, skips all prompts
crew init --no-interaction
```

### `sync` — Update everything at once

Runs `update:skill`, `update:subagent`, and `update:command` in sequence. Each step reuses the agents saved in `crew.json` and refreshes local resources plus any configured GitHub sources.

```bash
crew sync
```

### `new:*` — Scaffold a new resource locally

Create a new skill, sub-agent, or slash command definition from scratch with an interactive prompt.

```bash
# Interactive — prompts for name and description
crew new:skill
crew new:subagent
crew new:command

# Provide the name upfront to skip the name prompt
crew new:skill my-skill
crew new:subagent my-agent
crew new:command my-command
```

Generated files:

| Command | Output |
|---------|--------|
| `new:skill` | `.ai/skills/{name}/SKILL.md` |
| `new:subagent` | `.ai/agents/{name}.md` |
| `new:command` | `.ai/commands/{name}.md` |

Names must be lowercase alphanumeric with hyphens (1–64 chars, no leading/trailing/consecutive hyphens). `new:subagent` also prompts for a model choice (`sonnet`, `opus`, `haiku`, or `inherit`).

### `install:*` — Full setup flow

Runs the complete setup: detect agents, select them, sync local resources, and install from GitHub.

```bash
crew install:skill
crew install:subagent
crew install:command
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
crew add:command

# Direct — provide the repository
crew add:skill owner/repo
crew add:subagent owner/repo
crew add:command owner/repo

# Full GitHub URL with branch and subdirectory
crew add:skill https://github.com/owner/repo/tree/main/path/to/skills
```

### `update:*` — Update to the latest version

Re-runs the install flow in non-interactive mode to refresh all local and GitHub resources.

```bash
crew update:skill
crew update:subagent
crew update:command
```

## Configuration

Crew stores its configuration in `crew.json` at the project root:

```json
{
    "agents": ["claude_code", "junie"],
    "skills": ["owner/repo"],
    "subagents": ["owner/repo"],
    "commands": ["owner/repo"]
}
```

| Key | Type | Description |
|-----|------|-------------|
| `agents` | `string[]` | Selected agent identifiers |
| `skills` | `string[]` | GitHub repositories to install skills from |
| `subagents` | `string[]` | GitHub repositories to install sub-agents from |
| `commands` | `string[]` | GitHub repositories to install slash commands from |
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
eq  commands/
    my-command.md
```

Running `crew install:skill` copies skills from `.ai/skills/` to each selected agent's skill directory (e.g., `.claude/skills/my-skill/`, `.junie/skills/my-skill/`). The same pattern applies to sub-agents and slash commands.

### Remote Resources (GitHub)

Crew discovers resources in GitHub repositories by traversing the repo tree and looking for marker files (`SKILL.md`, agent markdown files, slash-command markdown files).

## Architecture

Crew is built with a contract-driven architecture. Each agent implements interfaces that define its capabilities:

- **SupportsSkills** — agent can receive skill files
- **SupportsSubAgents** — agent can receive sub-agent definitions
- **SupportsCommands** — agent can receive slash command files
- **SupportsGuidelines** — agent can receive guideline files
- **SupportsMcp** — agent can receive MCP server configurations

The detection system uses a strategy pattern to discover agents:

- **DirectoryDetectionStrategy** — checks for directories/paths (supports glob patterns)
- **FileDetectionStrategy** — checks for specific files in the project
- **CommandDetectionStrategy** — runs shell commands to detect installed tools
- **CompositeDetectionStrategy** — combines multiple strategies with OR logic

## Running in a Container (Claude on Host)

If `crew` runs inside a container but the AI agent (e.g. Claude Code) is installed on the host, the container's `command -v claude` won't see the host binary. The fix is to **bind-mount the host's binary and config into the container** so `command -v claude` resolves and crew's system detection passes.

### Find the host paths first

```bash
which claude          # e.g. /Users/you/.npm-global/bin/claude
ls ~/.claude          # config, settings, credentials
```

### docker-compose.yml

```yaml
services:
  app:
    image: php:8.3-cli
    working_dir: /workspace
    volumes:
      - .:/workspace
      # Claude config (settings, session, credentials)
      - ${HOME}/.claude:/root/.claude
      # Binary into a directory on container PATH
      - ${HOME}/.npm-global/bin/claude:/usr/local/bin/claude:ro
      # If installed via npm, mount its node_modules so the wrapper resolves
      - ${HOME}/.npm-global/lib/node_modules:/usr/local/lib/node_modules:ro
    environment:
      # Optional: macOS Keychain auth doesn't survive into a Linux container.
      # Use an API key, or run `claude /login` once inside the container.
      - ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}
```

### `docker run` one-liner

```bash
docker run --rm -it \
  -v "$PWD:/workspace" -w /workspace \
  -v "$HOME/.claude:/root/.claude" \
  -v "$(which claude):/usr/local/bin/claude:ro" \
  -v "$HOME/.npm-global/lib/node_modules:/usr/local/lib/node_modules:ro" \
  php:8.3-cli bash
```

### `.devcontainer/devcontainer.json`

```jsonc
{
  "image": "mcr.microsoft.com/devcontainers/php:8.3",
  "mounts": [
    "source=${localEnv:HOME}/.claude,target=/home/vscode/.claude,type=bind",
    "source=${localEnv:HOME}/.npm-global/bin/claude,target=/usr/local/bin/claude,type=bind,readonly",
    "source=${localEnv:HOME}/.npm-global/lib/node_modules,target=/usr/local/lib/node_modules,type=bind,readonly"
  ],
  "containerEnv": {
    "ANTHROPIC_API_KEY": "${localEnv:ANTHROPIC_API_KEY}"
  }
}
```

### Notes

- The container must have **Node.js** available — `claude` is a Node CLI. Use a base image with Node, or install it in your Dockerfile.
- Mount targets must match the container user's `$HOME`: `/root/...` for root images, `/home/vscode/...` for the Microsoft devcontainer images.
- **macOS quirk**: when you log in via `claude /login` on a macOS host, credentials may live in the Keychain rather than `~/.claude/.credentials.json`. Bind-mounting `~/.claude` won't carry those credentials into a Linux container — set `ANTHROPIC_API_KEY` in the container env, or run `claude /login` once from inside the container so credentials land in the mounted file.
- Once the mounts are active, `command -v claude` succeeds inside the container and `crew install:skill` detects `claude_code` with no code changes.

If you can't bind-mount the binary, you can still tell crew which agents are present by listing them in `crew.json` and passing `--skip-detection`:

```bash
crew install:skill --skip-detection
```

## License

MIT
