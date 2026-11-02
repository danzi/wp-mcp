const { success } = require('../response')
const { logDebug } = require('../logger')
const { readState, clearState } = require('../interactors/runtimeState')
const { stopPid } = require('../interactors/ngrokTunnel')

function sleep(ms) {
  return new Promise((resolve) => {
    setTimeout(resolve, ms)
  })
}

async function stopPublicFolder() {
  const state = readState()
  if (!state) {
    logDebug('stop: nothing running')
    return success({ stopped: false, reason: 'not_running' })
  }

  stopPid(state.ngrokPid)
  stopPid(state.servePid)
  await sleep(400)
  try {
    if (state.ngrokPid) {
      process.kill(state.ngrokPid, 'SIGKILL')
    }
  } catch (error) {
    logDebug('ngrok already gone', error.message)
  }
  try {
    if (state.servePid) {
      process.kill(state.servePid, 'SIGKILL')
    }
  } catch (error) {
    logDebug('serve already gone', error.message)
  }
  clearState()
  return success({
    stopped: true,
    publicBaseUrl: state.publicBaseUrl || null,
  })
}

module.exports = {
  stopPublicFolder,
}
