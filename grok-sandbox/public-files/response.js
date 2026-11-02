function success(data) {
  return {
    ok: true,
    error: null,
    data,
  }
}

function fail(message, extra) {
  const error = extra
    ? Object.assign(new Error(message), { extra })
    : new Error(message)
  throw error
}

function failPayload(message, extra) {
  return {
    ok: false,
    error: {
      message,
      extra: extra || null,
    },
    data: null,
  }
}

function printJson(payload) {
  process.stdout.write(`${JSON.stringify(payload, null, 2)}\n`)
}

module.exports = {
  success,
  fail,
  failPayload,
  printJson,
}
