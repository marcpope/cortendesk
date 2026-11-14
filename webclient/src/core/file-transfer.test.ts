import { describe, it, expect } from 'vitest';
import type { SessionEvent } from './contracts';
import {
  FT_BLOCK_SIZE,
  dirFromProto,
  entryFromProto,
  fileResponseToEvents,
  formatBytes,
  isDirKind,
  joinRemote,
  parentRemote,
  sepForPlatform,
} from './file-transfer';
import { FileEntry, FileType, type FileResponse } from '../gen/message';

const noZstd = (_: Uint8Array): Uint8Array => {
  throw new Error('no decompressor');
};

describe('entry conversion', () => {
  it('maps proto entries to plain objects with number sizes', () => {
    const e = entryFromProto(
      FileEntry.fromPartial({
        entry_type: FileType.File,
        name: 'a.txt',
        size: 12345n,
        modified_time: 1700000000n,
        is_hidden: false,
      }),
    );
    expect(e).toEqual({ kind: 'file', name: 'a.txt', size: 12345, modifiedSec: 1700000000, isHidden: false });
  });

  it('maps directory kinds', () => {
    expect(entryFromProto(FileEntry.fromPartial({ entry_type: FileType.Dir, name: 'd' })).kind).toBe('dir');
    expect(entryFromProto(FileEntry.fromPartial({ entry_type: FileType.DirDrive, name: 'C:' })).kind).toBe('drive');
    expect(entryFromProto(FileEntry.fromPartial({ entry_type: FileType.DirLink, name: 'l' })).kind).toBe('dirLink');
    expect(isDirKind('dir')).toBe(true);
    expect(isDirKind('drive')).toBe(true);
    expect(isDirKind('file')).toBe(false);
  });
});

describe('fileResponseToEvents', () => {
  it('converts a dir response', () => {
    const fr: FileResponse = {
      union: {
        $case: 'dir',
        dir: {
          id: 4,
          path: '/tmp',
          entries: [FileEntry.fromPartial({ entry_type: FileType.File, name: 'x', size: 1n })],
        },
      },
    };
    const evs = fileResponseToEvents(fr, noZstd);
    expect(evs).toHaveLength(1);
    expect(evs[0]).toMatchObject({ t: 'ftDir', dir: { id: 4, path: '/tmp' } });
  });

  it('passes uncompressed blocks through untouched', () => {
    const data = new Uint8Array([9, 8, 7]);
    const fr: FileResponse = {
      union: {
        $case: 'block',
        block: { id: 1, file_num: 0, data, compressed: false, blk_id: 0 },
      },
    };
    const evs = fileResponseToEvents(fr, noZstd);
    expect(evs[0]).toEqual({ t: 'ftBlock', id: 1, fileNum: 0, data, blkId: 0 });
  });

  it('decompresses compressed blocks and surfaces decompressor failure as ftError', () => {
    const fr: FileResponse = {
      union: {
        $case: 'block',
        block: { id: 2, file_num: 1, data: new Uint8Array([1]), compressed: true, blk_id: 0 },
      },
    };
    const ok = fileResponseToEvents(fr, () => new Uint8Array([5, 5]));
    expect(ok[0]).toMatchObject({ t: 'ftBlock', id: 2, data: new Uint8Array([5, 5]) });
    const bad = fileResponseToEvents(fr, noZstd);
    expect(bad[0]).toMatchObject({ t: 'ftError', id: 2, fileNum: 1 });
  });

  it('converts digest, done and error', () => {
    const digest: FileResponse = {
      union: {
        $case: 'digest',
        digest: {
          id: 3,
          file_num: 2,
          last_modified: 1700000001n,
          file_size: 42n,
          is_upload: true,
          is_identical: false,
          transferred_size: 10n,
          is_resume: true,
        },
      },
    };
    expect(fileResponseToEvents(digest, noZstd)[0]).toEqual({
      t: 'ftDigest',
      id: 3,
      fileNum: 2,
      lastModifiedSec: 1700000001,
      fileSize: 42,
      isUpload: true,
      isIdentical: false,
      transferredSize: 10,
      isResume: true,
    } satisfies SessionEvent);
    const done: FileResponse = { union: { $case: 'done', done: { id: 3, file_num: 5 } } };
    expect(fileResponseToEvents(done, noZstd)[0]).toEqual({ t: 'ftDone', id: 3, fileNum: 5 });
    const err: FileResponse = { union: { $case: 'error', error: { id: 3, error: 'nope', file_num: -1 } } };
    expect(fileResponseToEvents(err, noZstd)[0]).toEqual({ t: 'ftError', id: 3, fileNum: -1, error: 'nope' });
  });
});

describe('remote path arithmetic', () => {
  it('picks the separator from the platform string', () => {
    expect(sepForPlatform('Windows')).toBe('\\');
    expect(sepForPlatform('Linux')).toBe('/');
    expect(sepForPlatform('Mac OS')).toBe('/');
  });

  it('joins paths without doubling separators', () => {
    expect(joinRemote('/home/u', 'f.txt', '/')).toBe('/home/u/f.txt');
    expect(joinRemote('/', 'etc', '/')).toBe('/etc');
    expect(joinRemote('C:\\', 'Users', '\\')).toBe('C:\\Users');
    expect(joinRemote('C:\\Users', 'me', '\\')).toBe('C:\\Users\\me');
    expect(joinRemote('', 'x', '/')).toBe('x');
  });

  it('computes unix parents', () => {
    expect(parentRemote('/home/u/docs', '/')).toBe('/home/u');
    expect(parentRemote('/home', '/')).toBe('/');
    expect(parentRemote('/', '/')).toBe('/');
  });

  it('computes windows parents down to the drive list', () => {
    expect(parentRemote('C:\\Users\\me', '\\')).toBe('C:\\Users');
    expect(parentRemote('C:\\Users', '\\')).toBe('C:\\');
    expect(parentRemote('C:\\', '\\')).toBe('');
    expect(parentRemote('C:', '\\')).toBe('');
  });
});

describe('formatBytes', () => {
  it('formats human sizes', () => {
    expect(formatBytes(0)).toBe('0 B');
    expect(formatBytes(999)).toBe('999 B');
    expect(formatBytes(2048)).toBe('2.00 KB');
    expect(formatBytes(266280)).toBe('260 KB');
    expect(formatBytes(5 * 1024 * 1024)).toBe('5.00 MB');
    expect(formatBytes(-1)).toBe('—');
  });
  it('block size stays a sane power of two', () => {
    expect(FT_BLOCK_SIZE).toBe(131072);
  });
});

describe('dirFromProto', () => {
  it('converts a whole directory', () => {
    const d = dirFromProto({
      id: 9,
      path: 'C:\\Users',
      entries: [
        FileEntry.fromPartial({ entry_type: FileType.Dir, name: 'me' }),
        FileEntry.fromPartial({ entry_type: FileType.File, name: 'pagefile.sys', size: 100n, is_hidden: true }),
      ],
    });
    expect(d.id).toBe(9);
    expect(d.entries.map((e) => e.kind)).toEqual(['dir', 'file']);
    expect(d.entries[1]!.isHidden).toBe(true);
  });
});
