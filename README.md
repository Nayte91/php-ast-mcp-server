# PHP AST MCP Server

This is a Model Context Protocol (MCP) server that provides PHP Abstract Syntax Tree (AST) analysis capabilities.

Goal is to save tokens by providing a high level of abstraction over codebase.

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