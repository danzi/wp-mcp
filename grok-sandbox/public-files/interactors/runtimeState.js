const fs = require('fs')
const path = require('path')
const { fail } = require('../response')
const { logDebug } = require('../logger')

function stateDir() {
  return path.join(__dirname, '..', 'runtime')
}

function statePath() {
  return path.join(stateDir(), 'state.json')
}

function ensureRuntimeDir() {
  const dir = stateDir()
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true })
  }
}

function readState() {
  const file = statePath()
  if (!fs.existsSync(file)) {
    return null
  }
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'))
  } catch (error) {
    fail('runtime state is not valid JSON', { file, cause: error.message })
  }
}

function writeState(state) {
  ensureRuntimeDir()
  const file = statePath()
  fs.writeFileSync(file, `${JSON.stringify(state, null, 2)}\n`)
  logDebug('state written', { file })
}

function clearState() {
  const file = statePath()
  if (fs.existsSync(file)) {
    fs.unlinkSync(file)
    logDebug('state cleared', { file })
  }
}

module.exports = {
  stateDir,
  statePath,
  readState,
  writeState,
  clearState,
  ensureRuntimeDir,
}
