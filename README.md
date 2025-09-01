# PHP AST MCP Server

This is a Model Context Protocol (MCP) server that provides PHP Abstract Syntax Tree (AST) analysis capabilities.

Goal is to save tokens by providing a high level of abstraction over codebase.

## How much does it save

### Random codebase

I made a test on a random project's `src/` codebase:
- **145** PHP files
- **8,900 lines** of PHP code
- **~298.19 KB** as raw size

Comparison Results:
- Classic file reading: ~76,000 tokens
- With PHP-AST (all): ~33,000 tokens ➜ **57% less!**
- With PHP-AST (public): ~26,000 tokens ➜ **66% less!**

### Symfony + Doctrine vendors

Complete analysis of those 2 folders in `vendor/`:
- **6,325** PHP files
- **200,396 lines** of PHP code
- **31.92 MB** as raw size

Comparison Results:
- Classic file reading: ~8,369,000 tokens
- With PHP-AST (all): ~1,509,000 tokens ➜ **82% less!**
- With PHP-AST (public): ~1,173,000 tokens ➜ **86% less!**

## What it does

- **Parses PHP files** into their Abstract Syntax Tree representation using the `php-ast` extension
- **Analyzes code structure** including classes, methods, functions, and properties
- **Filters AST output** with options for public-only or all visibility levels
- **Processes directories** recursively to analyze entire PHP codebases
- **Provides JSON output** compatible with MCP clients for code analysis and tooling

## How it works

- Uses Docker Containerfile included
- Uses php-ast extension
- serves application with `php -S`
- load the `php-ast.json` in your agent's MCPs

## Usage

The server can be used by MCP-compatible clients to analyze PHP code structure and extract meaningful information.

Use it only locally, for dev purpose!