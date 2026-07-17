#!/usr/bin/env bash
set -euo pipefail

VHOST_DIR="${VHOST_DIR:-/usr/local/nginx/conf/vhost}"
BACKUP_DIR="${BACKUP_DIR:-}"
BACKUP_ROOT="${BACKUP_ROOT:-/www/backup/nginx}"
DRY_RUN=0
BACKUP=1

# /storage/ 下要拦截的可执行后缀(按组维护;~* 已大小写不敏感):
#   PHP 家族   : php php3..php9(php[0-9]*) phtml phtm pht phar phps
#   SSI        : shtml
#   CGI / 脚本 : cgi pl pm py rb sh bash
#   其它运行时 : asp aspx asa asax cer jsp jspx jspf war
#   Windows 投毒下载类: exe dll com bat cmd vbs vbe wsf hta ps1 msi scr
# 注意:不含 apk / jar / bin(App 版本分发等合法业务可能经 storage 下发)。
DEFAULT_EXTS='php[0-9]*|phtml|phtm|pht|phar|phps|shtml|cgi|pl|pm|py|rb|sh|bash|asp|aspx|asa|asax|cer|jsp|jspx|jspf|war|exe|dll|com|bat|cmd|vbs|vbe|wsf|hta|ps1|msi|scr'
EXTS="${STORAGE_DENY_EXTS:-$DEFAULT_EXTS}"

usage() {
    cat <<'EOF'
Usage:
  tools/fix-nginx-storage-safe.sh [options]

Options:
  --dir DIR       Directory to scan. Default: /usr/local/nginx/conf/vhost
  --backup-dir DIR
                  Directory to store backups. Default:
                  /www/backup/nginx/fix-nginx-storage-php-location/<timestamp>
  --exts LIST     Override the denied extension alternation (nginx regex
                  alternation, e.g. 'php[0-9]*|phtml|phar'). Allowed chars:
                  a-z 0-9 | [ ] * -
  --dry-run, -n   Show files that would change, but do not write changes
  --no-backup     Do not create backups before writing
  --help, -h      Show this help

The script scans every *.conf file under the directory and, in each nginx
server {} block:

  1. replaces the legacy nested /storage/ PHP deny block when present;
  2. upgrades any previously deployed flat /storage/ deny rule (older or
     customized extension lists, `return 404;` or `deny all;` bodies) to
     the current rule — rerunning after an extension-list change migrates
     already-deployed rules in place;
  3. ensures the current rule exists exactly once, before the first generic
     PHP regex location:

  location ~* ^/storage/.*\.(<EXTS>)(/.*)?$ { return 404; }

The trailing (/.*)? also blocks PATH_INFO-style bypasses (/storage/x.php/y).

After changing files, validate and reload nginx manually:
  nginx -t && nginx -s reload

Before updating files, existing same-directory backups matching *.conf.bak.*
are moved into <backup-dir>/migrated-existing/.
EOF
}

die() {
    printf '%s\n' "ERROR: $*" >&2
    exit 1
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dir)
            shift
            [ "$#" -gt 0 ] || die "--dir requires a value"
            VHOST_DIR="$1"
            ;;
        --dir=*)
            VHOST_DIR="${1#--dir=}"
            ;;
        --backup-dir)
            shift
            [ "$#" -gt 0 ] || die "--backup-dir requires a value"
            BACKUP_DIR="$1"
            ;;
        --backup-dir=*)
            BACKUP_DIR="${1#--backup-dir=}"
            ;;
        --exts)
            shift
            [ "$#" -gt 0 ] || die "--exts requires a value"
            EXTS="$1"
            ;;
        --exts=*)
            EXTS="${1#--exts=}"
            ;;
        --dry-run|-n)
            DRY_RUN=1
            ;;
        --no-backup)
            BACKUP=0
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            die "unknown option: $1"
            ;;
    esac
    shift
done

# 后缀清单只许出现 a-z 0-9 | [ ] * -:既防 nginx regex 语法被打坏,也保证
# 升级识别(变体匹配)与这里的目标串口径一致。([] 进 ERE 字符类:] 放最前)
printf '%s' "$EXTS" | grep -Eq '^[]a-z0-9|*[-]+$' \
    || die "--exts may only contain: a-z 0-9 | [ ] * -  (got: $EXTS)"
export STORAGE_DENY_EXTS="$EXTS"

while [ "$VHOST_DIR" != "/" ] && [ "${VHOST_DIR%/}" != "$VHOST_DIR" ]; do
    VHOST_DIR="${VHOST_DIR%/}"
done

[ -d "$VHOST_DIR" ] || die "directory not found: $VHOST_DIR"
command -v perl >/dev/null 2>&1 || die "perl is required"
command -v cmp >/dev/null 2>&1 || die "cmp is required"
command -v find >/dev/null 2>&1 || die "find is required"
command -v mktemp >/dev/null 2>&1 || die "mktemp is required"
command -v mkdir >/dev/null 2>&1 || die "mkdir is required"
command -v mv >/dev/null 2>&1 || die "mv is required"

timestamp=$(date +%Y%m%d%H%M%S)

if [ -z "$BACKUP_DIR" ]; then
    BACKUP_DIR="${BACKUP_ROOT}/fix-nginx-storage-php-location/${timestamp}"
fi

while [ "$BACKUP_DIR" != "/" ] && [ "${BACKUP_DIR%/}" != "$BACKUP_DIR" ]; do
    BACKUP_DIR="${BACKUP_DIR%/}"
done

next_backup_path() {
    local file="$1"
    local rel="${file#"$VHOST_DIR"/}"
    local candidate="${BACKUP_DIR}/${rel}.bak"
    local n=1

    while [ -e "$candidate" ]; do
        candidate="${BACKUP_DIR}/${rel}.bak.${n}"
        n=$((n + 1))
    done

    printf '%s\n' "$candidate"
}

next_migrated_backup_path() {
    local file="$1"
    local rel="${file#"$VHOST_DIR"/}"
    local candidate="${BACKUP_DIR}/migrated-existing/${rel}"
    local n=1

    while [ -e "$candidate" ]; do
        candidate="${BACKUP_DIR}/migrated-existing/${rel}.${n}"
        n=$((n + 1))
    done

    printf '%s\n' "$candidate"
}

migrate_existing_same_dir_backups() {
    local file
    local target
    local migrated=0

    while IFS= read -r -d '' file; do
        case "$file" in
            "$BACKUP_DIR"/*) continue ;;
        esac

        target=$(next_migrated_backup_path "$file")
        mkdir -p "${target%/*}"
        mv "$file" "$target"
        migrated=$((migrated + 1))
        printf '%s\n' "[migrate-backup] $file -> $target"
    done < <(find "$VHOST_DIR" -type f -name '*.conf.bak.*' -print0)

    if [ "$migrated" -gt 0 ]; then
        printf '%s\n' "[migrate-backup] moved=${migrated}"
    fi
}

render_file() {
    perl - "$1" <<'PERL'
use strict;
use warnings;

my $EXTS = $ENV{STORAGE_DENY_EXTS};
die "STORAGE_DENY_EXTS not set\n" unless defined $EXTS && length $EXTS;

my $TARGET_ARGS = '~* ^/storage/.*\.(' . $EXTS . ')(/.*)?$';
my $TARGET_BODY = 'return 404;';
my $TARGET_LOCATION = 'location ' . $TARGET_ARGS . ' { ' . $TARGET_BODY . ' }';
my $LEGACY_STORAGE_ARGS = '/storage/';
my $LEGACY_PHP_ARGS = '~ .*\.(php|php5)?$';

# 历史已部署的扁平 deny 规则(本脚本旧版产物 / 手工同形规则)的形态识别:
#   location ~*? ^/storage/.*\.(<任意后缀清单>)(/.*)?$ { return 404; | deny all; }
# 命中且与当前目标串不一致 → 原位替换为当前规则(自升级)。
my $VARIANT_ARGS_RE = qr{\A~\*?\s\^/storage/\.\*\\\.\([\]a-z0-9|*\[\-]+\)(?:\(/\.\*\)\?)?\$\z};

my $path = shift @ARGV;
open my $fh, '<', $path or die "open $path: $!\n";
local $/;
my $conf = <$fh>;
close $fh;

sub ident_char {
    my ($ch) = @_;
    return defined($ch) && $ch =~ /[A-Za-z0-9_-]/;
}

sub find_matching_brace {
    my ($text, $open_pos) = @_;
    my $len = length($text);
    my $depth = 0;
    my $state = 'normal';

    for (my $i = $open_pos; $i < $len; $i++) {
        my $ch = substr($text, $i, 1);

        if ($state eq 'normal') {
            if ($ch eq '#') {
                $state = 'comment';
            } elsif ($ch eq '"' || $ch eq "'") {
                $state = $ch;
            } elsif ($ch eq '{') {
                $depth++;
            } elsif ($ch eq '}') {
                $depth--;
                return $i if $depth == 0;
                return -1 if $depth < 0;
            }
        } elsif ($state eq 'comment') {
            $state = 'normal' if $ch eq "\n";
        } else {
            if ($ch eq '\\') {
                $i++;
            } elsif ($ch eq $state) {
                $state = 'normal';
            }
        }
    }

    return -1;
}

sub find_block_open {
    my ($text, $pos) = @_;
    my $len = length($text);
    my $state = 'normal';

    for (my $i = $pos; $i < $len; $i++) {
        my $ch = substr($text, $i, 1);

        if ($state eq 'normal') {
            if ($ch eq '#') {
                $state = 'comment';
            } elsif ($ch eq '"' || $ch eq "'") {
                $state = $ch;
            } elsif ($ch eq ';' || $ch eq '}') {
                return -1;
            } elsif ($ch eq '{') {
                return $i;
            }
        } elsif ($state eq 'comment') {
            $state = 'normal' if $ch eq "\n";
        } else {
            if ($ch eq '\\') {
                $i++;
            } elsif ($ch eq $state) {
                $state = 'normal';
            }
        }
    }

    return -1;
}

sub find_named_blocks {
    my ($text, $name) = @_;
    my @blocks;
    my $len = length($text);
    my $name_len = length($name);
    my $state = 'normal';

    for (my $i = 0; $i < $len; $i++) {
        my $ch = substr($text, $i, 1);

        if ($state eq 'normal') {
            if ($ch eq '#') {
                $state = 'comment';
                next;
            }
            if ($ch eq '"' || $ch eq "'") {
                $state = $ch;
                next;
            }
            next if $i + $name_len > $len;
            next if substr($text, $i, $name_len) ne $name;

            my $before = $i == 0 ? '' : substr($text, $i - 1, 1);
            my $after_pos = $i + $name_len;
            my $after = $after_pos >= $len ? '' : substr($text, $after_pos, 1);
            next if ident_char($before) || ident_char($after);

            my $open = find_block_open($text, $after_pos);
            next if $open < 0;
            my $close = find_matching_brace($text, $open);
            next if $close < 0;

            push @blocks, {
                start => $i,
                name_end => $after_pos,
                open => $open,
                close => $close,
            };
        } elsif ($state eq 'comment') {
            $state = 'normal' if $ch eq "\n";
        } else {
            if ($ch eq '\\') {
                $i++;
            } elsif ($ch eq $state) {
                $state = 'normal';
            }
        }
    }

    return @blocks;
}

sub brace_depth_at {
    my ($text, $pos) = @_;
    my $depth = 0;
    my $state = 'normal';

    for (my $i = 0; $i < $pos; $i++) {
        my $ch = substr($text, $i, 1);

        if ($state eq 'normal') {
            if ($ch eq '#') {
                $state = 'comment';
            } elsif ($ch eq '"' || $ch eq "'") {
                $state = $ch;
            } elsif ($ch eq '{') {
                $depth++;
            } elsif ($ch eq '}') {
                $depth--;
            }
        } elsif ($state eq 'comment') {
            $state = 'normal' if $ch eq "\n";
        } else {
            if ($ch eq '\\') {
                $i++;
            } elsif ($ch eq $state) {
                $state = 'normal';
            }
        }
    }

    return $depth;
}

sub strip_comments {
    my ($text) = @_;
    my $out = '';
    my $state = 'normal';
    my $len = length($text);

    for (my $i = 0; $i < $len; $i++) {
        my $ch = substr($text, $i, 1);

        if ($state eq 'normal') {
            if ($ch eq '#') {
                $state = 'comment';
                next;
            }
            if ($ch eq '"' || $ch eq "'") {
                $state = $ch;
            }
            $out .= $ch;
        } elsif ($state eq 'comment') {
            if ($ch eq "\n") {
                $out .= $ch;
                $state = 'normal';
            }
        } else {
            $out .= $ch;
            if ($ch eq '\\') {
                $i++;
                $out .= substr($text, $i, 1) if $i < $len;
            } elsif ($ch eq $state) {
                $state = 'normal';
            }
        }
    }

    return $out;
}

sub normalize_ws {
    my ($text) = @_;
    $text = strip_comments($text);
    $text =~ s/^\s+//s;
    $text =~ s/\s+\z//s;
    $text =~ s/\s+/ /g;
    return $text;
}

sub block_args {
    my ($text, $block) = @_;
    return normalize_ws(substr($text, $block->{name_end}, $block->{open} - $block->{name_end}));
}

sub block_body {
    my ($text, $block) = @_;
    return substr($text, $block->{open} + 1, $block->{close} - $block->{open} - 1);
}

sub is_target_rule_location {
    my ($server_block, $loc) = @_;
    return 0 unless brace_depth_at($server_block, $loc->{start}) == 1;
    return 0 unless block_args($server_block, $loc) eq $TARGET_ARGS;
    return normalize_ws(block_body($server_block, $loc)) eq $TARGET_BODY;
}

sub is_php_regex_location {
    my ($server_block, $loc) = @_;
    return 0 unless brace_depth_at($server_block, $loc->{start}) == 1;

    my $args = block_args($server_block, $loc);
    return 0 if $args eq $TARGET_ARGS;
    return 0 unless $args =~ /^~\*?\s+/;
    return $args =~ /php/i;
}

sub block_line_start {
    my ($text, $block) = @_;
    my $line_start = rindex($text, "\n", $block->{start});
    $line_start = $line_start < 0 ? 0 : $line_start + 1;

    my $prefix = substr($text, $line_start, $block->{start} - $line_start);
    return $prefix =~ /^[ \t]*\z/ ? $line_start : $block->{start};
}

sub block_line_end {
    my ($text, $block) = @_;
    my $end = $block->{close} + 1;
    $end++ if substr($text, $end, 1) eq "\n";
    return $end;
}

sub block_indent {
    my ($text, $block) = @_;
    my $line_start = block_line_start($text, $block);
    my $prefix = substr($text, $line_start, $block->{start} - $line_start);
    return $prefix =~ /^[ \t]*\z/ ? $prefix : '';
}

sub is_legacy_php_deny_location {
    my ($text, $loc) = @_;
    return 0 unless brace_depth_at($text, $loc->{start}) == 0;
    return 0 unless block_args($text, $loc) eq $LEGACY_PHP_ARGS;
    return normalize_ws(block_body($text, $loc)) eq 'deny all;';
}

sub is_legacy_storage_location {
    my ($server_block, $loc) = @_;
    return 0 unless brace_depth_at($server_block, $loc->{start}) == 1;
    return 0 unless block_args($server_block, $loc) eq $LEGACY_STORAGE_ARGS;

    my $body = block_body($server_block, $loc);
    my @matches;
    for my $inner (find_named_blocks($body, 'location')) {
        push @matches, $inner if is_legacy_php_deny_location($body, $inner);
    }
    return 0 unless @matches == 1;

    my $remaining = $body;
    my $match = $matches[0];
    substr($remaining, $match->{start}, $match->{close} - $match->{start} + 1) = '';
    $remaining = strip_comments($remaining);
    $remaining =~ s/\s+//g;
    return $remaining eq '';
}

# 历史扁平变体:同形(/storage/ 前缀 + 后缀 alternation)但清单/收尾与当前不同。
# body 接受 return 404;(旧版部署产物)和 deny all;(手工同形规则)。
sub is_storage_deny_variant {
    my ($server_block, $loc) = @_;
    return 0 unless brace_depth_at($server_block, $loc->{start}) == 1;

    my $args = block_args($server_block, $loc);
    return 0 if $args eq $TARGET_ARGS;
    return 0 unless $args =~ $VARIANT_ARGS_RE;

    my $body = normalize_ws(block_body($server_block, $loc));
    return $body eq $TARGET_BODY || $body eq 'deny all;';
}

sub replace_legacy_locations {
    my ($server_block) = @_;
    my $changed = 0;

    my @locations = sort { $b->{start} <=> $a->{start} } find_named_blocks($server_block, 'location');
    for my $loc (@locations) {
        next unless is_legacy_storage_location($server_block, $loc)
            || is_storage_deny_variant($server_block, $loc);
        substr($server_block, $loc->{start}, $loc->{close} - $loc->{start} + 1) = $TARGET_LOCATION;
        $changed = 1;
    }

    return ($server_block, $changed);
}

sub insert_target_rule {
    my ($server_block) = @_;
    my $close = length($server_block) - 1;
    my $line_start = rindex($server_block, "\n", $close);
    my ($insert_pos, $close_indent);

    if ($line_start >= 0) {
        my $prefix = substr($server_block, $line_start + 1, $close - $line_start - 1);
        if ($prefix =~ /^[ \t]*\z/) {
            $insert_pos = $line_start + 1;
            $close_indent = $prefix;
        } else {
            $insert_pos = $close;
            $close_indent = '';
        }
    } else {
        $insert_pos = $close;
        $close_indent = '';
    }

    my $before = substr($server_block, 0, $insert_pos);
    my $after = substr($server_block, $insert_pos);
    my $insert = '';
    $insert .= "\n" unless $before =~ /\n\z/;
    $insert .= $close_indent . '    ' . $TARGET_LOCATION . "\n";

    return $before . $insert . $after;
}

sub insert_target_rule_before_location {
    my ($server_block, $loc) = @_;
    my $insert_pos = block_line_start($server_block, $loc);
    my $indent = block_indent($server_block, $loc);
    my $before = substr($server_block, 0, $insert_pos);
    my $after = substr($server_block, $insert_pos);
    my $insert = $indent . $TARGET_LOCATION . "\n\n";

    return $before . $insert . $after;
}

sub ensure_target_rule {
    my ($server_block) = @_;

    my @locations = find_named_blocks($server_block, 'location');
    my @targets = grep { is_target_rule_location($server_block, $_) } @locations;
    my @php_locations = sort { $a->{start} <=> $b->{start} }
        grep { is_php_regex_location($server_block, $_) } @locations;
    my $first_php = @php_locations ? $php_locations[0] : undef;

    if (@targets == 1 && (!defined($first_php) || $targets[0]->{start} < $first_php->{start})) {
        return $server_block;
    }

    if (!@targets) {
        return defined($first_php)
            ? insert_target_rule_before_location($server_block, $first_php)
            : insert_target_rule($server_block);
    }

    for my $target (sort { $b->{start} <=> $a->{start} } @targets) {
        my $start = block_line_start($server_block, $target);
        my $end = block_line_end($server_block, $target);
        substr($server_block, $start, $end - $start) = '';
    }

    @locations = find_named_blocks($server_block, 'location');
    @php_locations = sort { $a->{start} <=> $b->{start} }
        grep { is_php_regex_location($server_block, $_) } @locations;
    $first_php = @php_locations ? $php_locations[0] : undef;

    return defined($first_php)
        ? insert_target_rule_before_location($server_block, $first_php)
        : insert_target_rule($server_block);
}

my @servers = sort { $b->{start} <=> $a->{start} } find_named_blocks($conf, 'server');
for my $server (@servers) {
    my $server_block = substr($conf, $server->{start}, $server->{close} - $server->{start} + 1);
    my ($updated_block) = replace_legacy_locations($server_block);
    $updated_block = ensure_target_rule($updated_block);

    next if $updated_block eq $server_block;
    substr($conf, $server->{start}, $server->{close} - $server->{start} + 1) = $updated_block;
}

print $conf;
PERL
}

found=0
changed=0

if [ "$BACKUP" -eq 1 ] && [ "$DRY_RUN" -eq 0 ]; then
    migrate_existing_same_dir_backups
fi

while IFS= read -r -d '' file; do
    found=$((found + 1))
    tmp=$(mktemp "${TMPDIR:-/tmp}/nginx-storage-conf.XXXXXX")

    if ! render_file "$file" > "$tmp"; then
        rm -f "$tmp"
        die "failed to process: $file"
    fi

    if cmp -s "$file" "$tmp"; then
        printf '%s\n' "[skip] $file"
        rm -f "$tmp"
        continue
    fi

    changed=$((changed + 1))
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '%s\n' "[dry-run] would update: $file"
        rm -f "$tmp"
        continue
    fi

    if [ "$BACKUP" -eq 1 ]; then
        backup=$(next_backup_path "$file")
        mkdir -p "${backup%/*}"
        cp -p "$file" "$backup"
        cp "$tmp" "$file"
        printf '%s\n' "[update] $file (backup: $backup)"
    else
        cp "$tmp" "$file"
        printf '%s\n' "[update] $file"
    fi

    rm -f "$tmp"
done < <(find "$VHOST_DIR" -type f -name '*.conf' -print0)

if [ "$found" -eq 0 ]; then
    printf '%s\n' "No .conf files found under: $VHOST_DIR"
else
    printf '%s\n' "Done. scanned=${found} changed=${changed}"
    if [ "$changed" -gt 0 ] && [ "$DRY_RUN" -eq 0 ]; then
        printf '%s\n' "Next: nginx -t && nginx -s reload"
    fi
fi
