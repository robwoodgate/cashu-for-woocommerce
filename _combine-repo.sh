#!/bin/bash

# --- Configuration ---
# Default output filename if OUTPUT_FILE env var is not set
default_output_filename="combined.txt"
# Determine output filename: Use env var if set, otherwise use default
output_filename="${OUTPUT_FILE:-$default_output_filename}"

# Default directories to exclude recursively if env vars don't override/append.
# Covers common VCS, build artifacts, dependency/cache folders, IDE configs, and tool caches.
# Names can include wildcards (e.g., "*.log", "*.egg-info").
default_exclude_dirs=(
    "node_modules" "target" "build" "dist" "out"    # JS, Java, general build outputs
    ".git" ".svn" ".hg"                             # Version control systems
    ".venv" "venv" "__pycache__"                   # Python virtual envs/cache
    "vendor" ".bundle"                             # Go/PHP/Ruby dependencies
    ".vscode" ".idea"                              # Common IDE directories
    ".pytest_cache" "*.egg-info"                   # Python testing/packaging cache/metadata
    ".aider*"                                      # Aider AI tool cache/config
    ".svelte-kit"
    ".sqlx"
    # Add other common ones if needed
)

# --- Helper Functions ---
# Function to display usage information
usage() {
    echo "Usage: $0 <pattern1> [pattern2] ..."
    echo "Example: $0 '*.js' '*.css'"
    echo ""
    echo "Combines files matching patterns into a single output file."
    echo "Prepends each file's content with a comment containing its absolute path."
    echo ""
    echo "Output File:"
    echo "  Defaults to: $default_output_filename"
    echo "  Customize by setting the OUTPUT_FILE environment variable."
    echo "  Example: export OUTPUT_FILE=\"my_custom_output.txt\""
    echo ""
    echo "Exclusion (Directory Names - Wildcards Supported):"
    echo "  Default excluded names: ${default_exclude_dirs[*]}"
    echo ""
    echo "  Customize via Environment Variables:"
    echo "  1. COMBINE_EXCLUDE_DIRS: If set, REPLACES the default list entirely."
    echo "     Example: export COMBINE_EXCLUDE_DIRS=\"build .git\""
    echo "  2. COMBINE_APPEND_EXCLUDE_DIRS: If set (and COMBINE_EXCLUDE_DIRS is NOT set),"
    echo "     APPENDS to the default list."
    echo "     Example: export COMBINE_APPEND_EXCLUDE_DIRS=\".mypattern *.log\""
    echo ""
}

# --- Initialization ---
# Get the absolute path of the current working directory
script_cwd=$(pwd)
if [ -z "$script_cwd" ]; then
    echo "Error: Could not determine current working directory." >&2
    exit 1
fi

# Check if at least one file pattern argument was provided
if [ $# -eq 0 ]; then
    usage
    echo "Error: Please provide at least one file pattern as an argument." >&2
    exit 1
fi

# --- Determine Directories to Exclude ---
exclude_dirs=() # Initialize the final list
echo "Determining exclusion list..."

# Check precedence: COMBINE_EXCLUDE_DIRS overrides everything if set
if [ -n "$COMBINE_EXCLUDE_DIRS" ]; then
    # REPLACE logic
    read -r -a exclude_dirs <<< "$COMBINE_EXCLUDE_DIRS"
    echo "REPLACING default excludes with custom list from COMBINE_EXCLUDE_DIRS: ${exclude_dirs[*]}"
elif [ -n "$COMBINE_APPEND_EXCLUDE_DIRS" ]; then
    # APPEND logic: Start with defaults, then add from the append variable
    exclude_dirs=("${default_exclude_dirs[@]}")
    read -r -a append_dirs <<< "$COMBINE_APPEND_EXCLUDE_DIRS"
    # Append the new directories to the existing list
    exclude_dirs+=("${append_dirs[@]}")
    echo "Using default excludes AND appending from COMBINE_APPEND_EXCLUDE_DIRS."
    echo "Final exclusion list: ${exclude_dirs[*]}"
else
    # DEFAULT logic: Neither replace nor append variable is set
    exclude_dirs=("${default_exclude_dirs[@]}")
    echo "Using default recursive exclude directory names (wildcards ok): ${exclude_dirs[*]}"
    echo "(Set COMBINE_EXCLUDE_DIRS to replace, or COMBINE_APPEND_EXCLUDE_DIRS to append to defaults)"
fi

# --- Build `find` command exclusion arguments dynamically ---
find_prune_args=()
if [ ${#exclude_dirs[@]} -gt 0 ]; then
    find_prune_args+=(\() # Start grouping for -prune conditions
    first_dir=true
    for dir in "${exclude_dirs[@]}"; do
        # Skip empty directory names that might result from splitting the env var
        if [ -z "$dir" ]; then
            continue
        fi
        if ! $first_dir; then
            find_prune_args+=(-o) # Add OR between directories
        fi
        # Match directory by NAME (-name), which supports wildcards, ensure it's a directory (-type d)
        find_prune_args+=(-name "$dir" -type d)
        first_dir=false
    done
    find_prune_args+=(\)) # End grouping
    find_prune_args+=(-prune -o) # Add the prune action and the OR separator for the main condition
else
    echo "No directory names specified for exclusion."
fi


# --- Main Processing ---
# Use the determined output filename (from env var or default)
echo "Output file will be: $output_filename"

# Remove the output file if it already exists to start fresh
if [ -f "$output_filename" ]; then
    rm "$output_filename"
    echo "Removed existing $output_filename"
fi

echo "Combining files matching patterns: $@"
echo "Base directory: $script_cwd"

# Loop through each pattern provided as a command-line argument
found_files=false
for pattern in "$@"; do
    # Construct the final find command parts in an array for safety
    find_command=(find . "${find_prune_args[@]}" -type f -name "$pattern" -print)

    # Execute find and process results line by line
    while IFS= read -r file; do
        found_files=true # Mark that we found at least one file overall
        # Construct the full path manually
        full_path="$script_cwd/${file#./}"

        echo "Adding: $full_path" # Optional: Log which file is being added
        # Append a comment header with the constructed full path to the output file
        echo "// $full_path" >> "$output_filename"
        # Append the content of the found file
        cat "$file" >> "$output_filename"
        # Append a newline for separation
        echo "" >> "$output_filename"
    done < <("${find_command[@]}") # Execute find command and pipe its stdout to the while loop

done

# --- Final Message ---
if [ "$found_files" = true ]; then
    echo "All found files matching patterns $* (respecting exclusions) have been combined into $output_filename"
else
    echo "No files found matching the patterns: $* (respecting exclusions). Output file '$output_filename' not created."
fi

exit 0

