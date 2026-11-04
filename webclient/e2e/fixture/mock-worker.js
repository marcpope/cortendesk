// Mock session worker: "connects" at once to a Windows host with one
// 1920x1080 display, paints a test pattern, and otherwise ignores commands
// (the page records them before they get here).
self.onmessage = (e) => {
  const cmd = e.data;
  // Test hooks: the connection dies (as on a Windows sign-in), or the host closes it on purpose.
  if (cmd.c === '__drop') {
    self.postMessage({ t: 'state', state: 'error', detail: 'Connection lost (relay)' });
    return;
  }
  if (cmd.c === '__peerclose') {
    self.postMessage({ t: 'state', state: 'closed', detail: 'Closed manually by the peer', peerInitiated: true });
    return;
  }
  if (cmd.c !== 'connect') return;
  const canvas = cmd.canvas;
  if (canvas) paint(canvas);
  const display = { index: 0, x: 0, y: 0, width: 1920, height: 1080, name: 'DISPLAY1', scale: 1, online: true, cursorEmbedded: false, resolutions: [] };
  self.postMessage({ t: 'state', state: 'streaming' });
  self.postMessage({
    t: 'peerInfo', displays: [display], username: 'user', hostname: 'HOST', platform: 'Windows',
    platformAdditions: '', version: '1.5.1', current: 0, privacyModeSupported: false, privacyModeImpls: [],
    terminalSupported: false, viewCameraSupported: false,
  });
};

function paint(canvas) {
  canvas.width = 1920;
  canvas.height = 1080;
  const g = canvas.getContext('2d');
  g.fillStyle = '#204060';
  g.fillRect(0, 0, 1920, 1080);
  g.fillStyle = '#fff';
  for (let x = 0; x < 1920; x += 120) g.fillRect(x, 0, 2, 1080);
  for (let y = 0; y < 1080; y += 120) g.fillRect(0, y, 1920, 2);
  g.fillStyle = '#0a0';
  g.fillRect(0, 1040, 1920, 40); // "taskbar"
}
