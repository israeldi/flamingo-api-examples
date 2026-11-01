"""Create one private RapidAPI key file locally; no network or processing.

Run in your own private Terminal on the same host and OS user as the client:
    python3 setup_key.py
Then use --key-file ~/.config/flamingo-api/rapidapi.key with a client.
A laptop key file does not configure a remote agent host. Never attach it to chat.
"""
import argparse
import getpass
import os
from pathlib import Path
import stat
import sys
import warnings

MAX_KEY_BYTES = 4096


class SetupError(ValueError):
    pass


def create_key_file(config_dir, key):
    """Create a new private file; direct fake-key calls are used only by tests."""
    if os.name != 'posix' or not all(hasattr(os, name) for name in ('O_NOFOLLOW', 'O_NONBLOCK', 'O_DIRECTORY', 'geteuid')):
        raise SetupError('Private key-file setup requires a supported POSIX host.')
    if (not isinstance(key, str) or not 16 <= len(key) <= 512
            or len(key) + 1 > MAX_KEY_BYTES or any(ord(c) < 33 or ord(c) > 126 for c in key)):
        raise SetupError('Enter a private key of 16 to 512 printable ASCII characters without whitespace or control characters.')
    try:
        absolute = Path(os.path.abspath(Path(config_dir).expanduser()))
        if absolute == Path(absolute.anchor):
            raise SetupError('Use a private configuration directory outside a project.')
        # A worktree's .git can be a file; no contents or credentials are read.
        if any((part / '.git').exists() or (part / '.git').is_symlink()
               for part in (absolute, *absolute.parents)):
            raise SetupError('Keep the private key configuration outside a Git project.')
    except (OSError, TypeError, ValueError) as exc:
        if isinstance(exc, SetupError):
            raise
        raise SetupError('Use a private configuration directory outside a project.') from None
    directory = descriptor = None
    created = False
    try:
        flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_NONBLOCK
        directory = os.open(absolute.anchor, flags)
        for part in absolute.parts[1:]:
            try:
                child = os.open(part, flags, dir_fd=directory)
            except FileNotFoundError:
                os.mkdir(part, 0o700, dir_fd=directory)
                child = os.open(part, flags, dir_fd=directory)
            os.close(directory)
            directory = child
        parent = os.fstat(directory)
        if parent.st_uid != os.geteuid() or stat.S_IMODE(parent.st_mode) != 0o700:
            raise SetupError('The configuration directory must already be owned by this user with mode 700; no permissions were changed.')
        descriptor = os.open('rapidapi.key', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_NONBLOCK,
                             0o600, dir_fd=directory)
        created = True
        info = os.fstat(descriptor)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_nlink != 1):
            raise SetupError('Could not create the required private key file.')
        data = (key + '\n').encode('ascii')
        offset = 0
        while offset < len(data):
            written = os.write(descriptor, data[offset:])
            if written <= 0:
                raise SetupError('Could not save the private key file.')
            offset += written
        os.fsync(descriptor)
        return absolute / 'rapidapi.key'
    except FileExistsError:
        raise SetupError('The key path already exists. It was not read, overwritten or rotated.') from None
    except (OSError, ValueError) as exc:
        if created and directory is not None:
            try:
                opened = os.fstat(descriptor)
                named = os.stat('rapidapi.key', dir_fd=directory, follow_symlinks=False)
                if (opened.st_dev, opened.st_ino) == (named.st_dev, named.st_ino):
                    os.unlink('rapidapi.key', dir_fd=directory)
            except OSError:
                pass
        if isinstance(exc, SetupError):
            raise
        raise SetupError('Could not create a private key file; use an owned mode-700 directory without symlinks.') from None
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if directory is not None:
            os.close(directory)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.parse_args(argv)
    try:
        if not sys.stdin.isatty() or not sys.stderr.isatty():
            raise SetupError('Run setup in your own private Terminal on the same host and OS user as the client.')
        with warnings.catch_warnings():
            warnings.simplefilter('error', getpass.GetPassWarning)
            key = getpass.getpass('RapidAPI application key (hidden): ')
        path = create_key_file(Path.home() / '.config' / 'flamingo-api', key)
        print('Private key file created: ' + str(path))
        return 0
    except (SetupError, getpass.GetPassWarning, EOFError, KeyboardInterrupt, OSError):
        print('Private key setup did not complete. Use a private Terminal and a new owned configuration path; no existing key was changed.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
