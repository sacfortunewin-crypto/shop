const {
  buildTransactionPayload,
  errorMessage,
  pagouApiRequest,
  readJson,
  requireMethod,
  sendJson,
} = require("./_lib/pagou");

module.exports = async function handler(req, res) {
  if (!requireMethod(req, res, "POST")) return;

  let input;
  try {
    input = await readJson(req);
  } catch {
    sendJson(res, 400, { message: "JSON invalido." });
    return;
  }

  const payload = buildTransactionPayload(input, req, res);
  if (!payload) return;

  const response = await pagouApiRequest("POST", "/v2/transactions", payload);
  const body = response.body || {};

  if (response.status < 200 || response.status >= 300) {
    sendJson(res, response.status > 0 ? response.status : 502, {
      message: errorMessage(body),
      pagouStatus: response.status,
      requestId: body.requestId || null,
    });
    return;
  }

  const data = body.data && typeof body.data === "object" ? body.data : body;
  const transactionId = data.id || body.transactionId || null;

  if (payload.method === "pix") {
    const pix = data.pix && typeof data.pix === "object" ? data.pix : {};

    sendJson(res, 200, {
      transactionId,
      status: data.status || null,
      method: data.method || "pix",
      amount: data.amount || payload.amount,
      currency: data.currency || "BRL",
      requestId: body.requestId || null,
      pix: {
        qrCode: pix.qr_code || body.pixQrCode || null,
        qrCodeImage: pix.qr_code_image || body.pixQrCodeImage || null,
        expirationDate: pix.expiration_date || null,
        receiptUrl: pix.receipt_url || null,
      },
    });
    return;
  }

  sendJson(res, 200, {
    ...data,
    transactionId,
    requestId: body.requestId || null,
    transaction: data,
  });
};
