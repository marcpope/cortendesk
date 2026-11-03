/**
 * What changed in a text field, as the keystrokes that make the same change
 * at the end of the remote text: delete N code points, then type the rest.
 *
 * Phone keyboards do not type one key at a time. Autocorrect swaps the last
 * word, Gboard rewrites its composing word on every key, dictation revises
 * whole phrases. Diffing the field against what we saw last turns all of that
 * into Backspaces plus inserted text, which is what the remote side needs to
 * end up with the same characters (noVNC and Guacamole do the same).
 *
 * Counting is by code point, not UTF-16 unit, so an emoji is one character
 * and a Thaana fili (a combining mark) is its own character, the way a
 * Backspace on the host removes it.
 */
export type TextDiff = { deleted: number; inserted: string };

export function diffText(before: string, after: string): TextDiff {
  const a = Array.from(before);
  const b = Array.from(after);
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i++;
  return { deleted: a.length - i, inserted: b.slice(i).join('') };
}
