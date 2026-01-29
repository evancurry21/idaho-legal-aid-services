#!/bin/bash

# Install the chatbot pre-push hook.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
HOOKS_DIR="$PROJECT_ROOT/.git/hooks"

if [ ! -d "$HOOKS_DIR" ]; then
  echo "Error: .git/hooks directory not found. Are you in a git repo?"
  exit 1
fi

# Copy hook.
cp "$SCRIPT_DIR/pre-push" "$HOOKS_DIR/pre-push"
chmod +x "$HOOKS_DIR/pre-push"

echo "Pre-push hook installed successfully!"
echo ""
echo "The hook will run validation checks when you push changes to:"
echo "  - config/routing/*.yml"
echo "  - ilas_site_assistant.settings.yml"
echo "  - chatbot-golden-dataset.csv"
echo ""
echo "To uninstall: rm .git/hooks/pre-push"
