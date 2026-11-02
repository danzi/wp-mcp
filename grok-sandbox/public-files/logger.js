function isDebugEnabled() {
  return process.env.DEBUG === '1' || process.env.DEBUG === 'true'
}

function logDebug(message, extra) {
  if (!isDebugEnabled()) {
    return
  }
  if (extra === undefined) {
    console.error('[debug]', message)
    return
  }
  console.error('[debug]', message, extra)
}

module.exports = {
  isDebugEnabled,
  logDebug,
}
