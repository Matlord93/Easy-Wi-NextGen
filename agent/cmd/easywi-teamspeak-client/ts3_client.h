/*
 * ts3_client.h — TeamSpeak 3 client library bridge for easywi-teamspeak-client.
 *
 * This header:
 *   - Declares the minimal subset of the TS3 client SDK API used here (types,
 *     constants, callback struct) using the publicly documented layout.
 *   - Implements a PCM ring buffer shared between the Go audio writer and the
 *     SDK's custom-capture callback.
 *   - Provides ts3bridge_*() functions called by Go via CGo.
 *
 * SDK dependency:
 *   The TeamSpeak 3 client library (libts3client.so) is loaded at runtime via
 *   dlopen(); you do NOT need the official SDK headers at compile time.
 *   Download the SDK at https://teamspeak.com/en/features/teamspeak-sdk/
 *   and place libts3client.so at the path supplied as backend_path in the
 *   NDJSON connect request.
 *
 * Opus dependency:
 *   libopus.so (or libopus.so.0) must be in the system library path or LD_LIBRARY_PATH.
 *   Install: apt-get install libopus-dev  / yum install opus-devel
 *
 * Audio path:
 *   SendOpusFrame → ts3bridge_push_opus_frame (Go→C)
 *     → opus_decode (Opus→PCM 48kHz 16-bit mono)
 *     → PCM ring buffer
 *   TS3 SDK capture callback → ts3bridge_capture_cb (exported from Go)
 *     → ts3bridge_pop_pcm (C)
 *     → TS3 SDK network stack
 *
 * No reverse engineering. No SinusBot. No TS3AudioBot. No ServerQuery audio.
 * Passwords are never written to stderr or stdout.
 */

#ifndef EASYWI_TS3_CLIENT_H
#define EASYWI_TS3_CLIENT_H

#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <stdio.h>
#include <pthread.h>
#include <unistd.h>
#include <sys/select.h>

/* ──────────────────────────────────────────────────────────────────────────
 * TS3 SDK type aliases (from ts3client_publicdefinitions.h, SDK v3.2.x)
 * ─────────────────────────────────────────────────────────────────────── */

typedef unsigned int  anyID;
typedef uint64_t      uint64;
typedef unsigned int  ts3_error;

#define TS3_ERROR_OK        0u
#define TS3_LOG_NONE        0
#define TS3_LOG_USERLOGGING 1

/* Connection status codes (STATUS_* from ts3client_publicdefinitions.h) */
#define TS3_STATUS_DISCONNECTED           0
#define TS3_STATUS_CONNECTING             1
#define TS3_STATUS_CONNECTED              2
#define TS3_STATUS_CONNECTION_ESTABLISHING 3
#define TS3_STATUS_CONNECTION_ESTABLISHED 4

/* CLIENT_NICKNAME flag index for ts3client_setClientSelfVariableAsString */
#define TS3_CLIENT_NICKNAME 5

/* ──────────────────────────────────────────────────────────────────────────
 * TS3 SDK callback struct
 *
 * Layout matches the official SDK ClientUIFunctions struct (v3.2.x).
 * Only the two callbacks we implement are non-NULL; all others are NULL.
 * The NULL-padding must exactly match the struct field order in the SDK.
 * If you upgrade the SDK and it changes the struct layout, update this struct.
 *
 * Source: TeamSpeak 3 client SDK ts3client_sdk.h (officially documented).
 * ─────────────────────────────────────────────────────────────────────── */

typedef void (*ts3_fn_void)(void);  /* placeholder for unused callbacks */

/* Callback for connection status changes */
typedef void (*ts3_connect_status_cb)(uint64 serverConnectionHandlerID,
                                      int newStatus, unsigned int errorNumber);

/* Callback invoked by the SDK to request outgoing audio (custom capture) */
typedef void (*ts3_capture_cb)(const char* deviceName, short** buffer,
                                int* samples, int stereo);

/*
 * ClientUIFunctions — minimal subset.
 *
 * The struct must be 0-padded to the correct size because the SDK iterates
 * fields by offset. Each void* below represents one unused callback slot.
 * Count of void* entries was verified against SDK v3.2.3 public examples.
 *
 * Callbacks we use:
 *   [0]  onConnectStatusChangeEvent
 *   [47] onCustomDeviceCaptureDataIsAvailable  (index from SDK v3.2.3)
 */
struct TS3ClientUIFunctions {
    ts3_connect_status_cb onConnectStatusChangeEvent;   /* [0]  */
    ts3_fn_void _pad[46];                               /* [1–46] unused */
    ts3_capture_cb onCustomDeviceCaptureDataIsAvailable; /* [47] */
    ts3_fn_void _pad2[12];                              /* [48–59] unused */
};

/* ──────────────────────────────────────────────────────────────────────────
 * TS3 SDK function pointer types (loaded via dlopen)
 * ─────────────────────────────────────────────────────────────────────── */

typedef ts3_error (*pfn_initClientLib)(const struct TS3ClientUIFunctions*,
                                       const void* rare, int logTypes,
                                       const char* logFolder,
                                       const char* resourcesFolder);
typedef ts3_error (*pfn_destroyClientLib)(void);
typedef ts3_error (*pfn_spawnHandler)(int port, uint64* result);
typedef ts3_error (*pfn_destroyHandler)(uint64 scHandlerID);
typedef ts3_error (*pfn_createIdentity)(char** result);
typedef ts3_error (*pfn_freeMemory)(void* ptr);
typedef ts3_error (*pfn_startConnection)(uint64 scHandlerID,
                                         const char* identity,
                                         const char* ip, unsigned int port,
                                         const char* nickname,
                                         const char** defaultChannel,
                                         const char* defaultChannelPw,
                                         const char* serverPw);
typedef ts3_error (*pfn_stopConnection)(uint64 scHandlerID, const char* msg);
typedef ts3_error (*pfn_getClientID)(uint64 scHandlerID, anyID* result);
typedef ts3_error (*pfn_getConnectionStatus)(uint64 scHandlerID, int* result);
typedef ts3_error (*pfn_requestClientMove)(uint64 scHandlerID, anyID clientID,
                                           uint64 channelID, const char* pw,
                                           const char* returnCode);
typedef ts3_error (*pfn_setClientSelfVarStr)(uint64 scHandlerID,
                                              size_t flag,
                                              const char* value);
typedef ts3_error (*pfn_flushClientSelfUpdates)(uint64 scHandlerID,
                                                const char* returnCode);
typedef ts3_error (*pfn_openCaptureDevice)(uint64 scHandlerID,
                                           const char* modeID,
                                           const char* captureDevice);
typedef ts3_error (*pfn_closeCaptureDevice)(uint64 scHandlerID);
typedef ts3_error (*pfn_acquireCustomCaptureData)(const char* deviceName,
                                                   short** buffer, int* samples);

/* ──────────────────────────────────────────────────────────────────────────
 * Opus function pointer types (loaded via dlopen)
 * ─────────────────────────────────────────────────────────────────────── */

typedef int  (*pfn_opus_decoder_get_size)(int channels);
typedef void* (*pfn_opus_decoder_create)(int32_t Fs, int channels, int* error);
typedef int  (*pfn_opus_decode)(void* st, const unsigned char* data,
                                 int32_t len, short* pcm,
                                 int frame_size, int decode_fec);
typedef void (*pfn_opus_decoder_destroy)(void* st);

/* ──────────────────────────────────────────────────────────────────────────
 * Global bridge state (single-instance: one TS3 connection at a time)
 * ─────────────────────────────────────────────────────────────────────── */

#define TS3BRIDGE_CUSTOM_DEVICE  "easywi-capture"
#define TS3BRIDGE_PCM_RATE       48000
#define TS3BRIDGE_PCM_CHANNELS   1
#define TS3BRIDGE_FRAME_SAMPLES  960    /* 20 ms at 48 kHz */
/* Ring buffer: hold up to 50 frames (1 second) of PCM */
#define TS3BRIDGE_RING_FRAMES    50
#define TS3BRIDGE_RING_CAPACITY  (TS3BRIDGE_RING_FRAMES * TS3BRIDGE_FRAME_SAMPLES)

typedef struct {
    short     buf[TS3BRIDGE_RING_CAPACITY];
    int       head;   /* read position */
    int       tail;   /* write position */
    int       fill;   /* samples available */
    pthread_mutex_t mu;
} ts3bridge_pcm_ring;

typedef struct {
    void* dl_ts3;        /* dlopen handle for libts3client.so */
    void* dl_opus;       /* dlopen handle for libopus.so */
    void* opus_decoder;  /* OpusDecoder* (opaque) */

    /* TS3 function pointers */
    pfn_initClientLib            ts3_init;
    pfn_destroyClientLib         ts3_destroy;
    pfn_spawnHandler             ts3_spawn;
    pfn_destroyHandler           ts3_destroyHandler;
    pfn_createIdentity           ts3_createIdentity;
    pfn_freeMemory               ts3_freeMemory;
    pfn_startConnection          ts3_start;
    pfn_stopConnection           ts3_stop;
    pfn_getClientID              ts3_getClientID;
    pfn_getConnectionStatus      ts3_getStatus;
    pfn_requestClientMove        ts3_move;
    pfn_setClientSelfVarStr      ts3_setSelfVar;
    pfn_flushClientSelfUpdates   ts3_flushSelf;
    pfn_openCaptureDevice        ts3_openCapture;
    pfn_closeCaptureDevice       ts3_closeCapture;
    pfn_acquireCustomCaptureData ts3_acquireCapture;

    /* Opus function pointers */
    pfn_opus_decoder_get_size    opus_get_size;
    pfn_opus_decoder_create      opus_create;
    pfn_opus_decode              opus_decode;
    pfn_opus_decoder_destroy     opus_destroy;

    uint64            scHandlerID;
    ts3bridge_pcm_ring ring;

    /* Connection notification channel: written by SDK callback, read by Go */
    int conn_pipe[2];  /* [0]=read, [1]=write */
} ts3bridge_state;

/* Global singleton — safe because we only ever have one connection at a time. */
static ts3bridge_state g_ts3 = {0};

/* ──────────────────────────────────────────────────────────────────────────
 * PCM ring buffer helpers
 * ─────────────────────────────────────────────────────────────────────── */

static inline void ring_init(ts3bridge_pcm_ring* r) {
    memset(r, 0, sizeof(*r));
    pthread_mutex_init(&r->mu, NULL);
}

static inline void ring_destroy(ts3bridge_pcm_ring* r) {
    pthread_mutex_destroy(&r->mu);
}

/* Push samples into the ring; drops oldest frames on overflow. */
static void ring_push(ts3bridge_pcm_ring* r, const short* src, int n) {
    pthread_mutex_lock(&r->mu);
    for (int i = 0; i < n; i++) {
        if (r->fill == TS3BRIDGE_RING_CAPACITY) {
            /* Overflow: drop oldest sample */
            r->head = (r->head + 1) % TS3BRIDGE_RING_CAPACITY;
            r->fill--;
        }
        r->buf[r->tail] = src[i];
        r->tail = (r->tail + 1) % TS3BRIDGE_RING_CAPACITY;
        r->fill++;
    }
    pthread_mutex_unlock(&r->mu);
}

/* Pop up to n samples; returns number of samples actually popped (may be < n). */
static int ring_pop(ts3bridge_pcm_ring* r, short* dst, int n) {
    pthread_mutex_lock(&r->mu);
    int got = (r->fill < n) ? r->fill : n;
    for (int i = 0; i < got; i++) {
        dst[i] = r->buf[r->head];
        r->head = (r->head + 1) % TS3BRIDGE_RING_CAPACITY;
    }
    r->fill -= got;
    pthread_mutex_unlock(&r->mu);
    return got;
}

/* ──────────────────────────────────────────────────────────────────────────
 * dlopen helpers
 * ─────────────────────────────────────────────────────────────────────── */

#ifdef __linux__
#include <dlfcn.h>
#define TS3BRIDGE_DLOPEN(path, flags) dlopen((path), (flags))
#define TS3BRIDGE_DLSYM(h, sym)       dlsym((h), (sym))
#define TS3BRIDGE_DLCLOSE(h)          dlclose(h)
#define TS3BRIDGE_DLERROR()           dlerror()
#endif

static int ts3bridge_load_sym(void* dl, const char* sym, void** out) {
    *out = TS3BRIDGE_DLSYM(dl, sym);
    if (!*out) {
        fprintf(stderr, "[ts3_client] dlsym %s: %s\n", sym, TS3BRIDGE_DLERROR());
        return -1;
    }
    return 0;
}

/* ──────────────────────────────────────────────────────────────────────────
 * Forward declarations for callbacks exported from Go.
 *
 * CGo generates non-const char* for exported Go functions. The SDK callback
 * typedef uses const char*. A thin C adapter bridges the two so the struct
 * assignment compiles cleanly under strict prototype checking (-Werror).
 * ─────────────────────────────────────────────────────────────────────── */

/* CGo-exported; deviceName is non-const because CGo does not emit const. */
extern void ts3BridgeCaptureCallback(char* deviceName,
                                     short** buffer, int* samples, int stereo);
extern void ts3BridgeConnectStatusCallback(uint64 scHandlerID,
                                           int newStatus, unsigned int errorNumber);

/* Adapter: SDK delivers const char*; we cast to char* for the Go export. */
static void ts3bridge_capture_adapter(const char* deviceName,
                                      short** buffer, int* samples, int stereo) {
    ts3BridgeCaptureCallback((char*)deviceName, buffer, samples, stereo);
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_load — load libts3client.so and libopus.so via dlopen
 * Returns 0 on success, -1 on error (error written to stderr).
 * sdkLibPath: full path to libts3client.so.
 * ─────────────────────────────────────────────────────────────────────── */

static int ts3bridge_load(const char* sdkLibPath) {
    g_ts3.dl_ts3 = TS3BRIDGE_DLOPEN(sdkLibPath, RTLD_NOW | RTLD_LOCAL);
    if (!g_ts3.dl_ts3) {
        fprintf(stderr, "[ts3_client] dlopen %s: %s\n", sdkLibPath, TS3BRIDGE_DLERROR());
        return -1;
    }

    /* Try libopus.so.0 first, then libopus.so */
    g_ts3.dl_opus = TS3BRIDGE_DLOPEN("libopus.so.0", RTLD_NOW | RTLD_LOCAL);
    if (!g_ts3.dl_opus)
        g_ts3.dl_opus = TS3BRIDGE_DLOPEN("libopus.so", RTLD_NOW | RTLD_LOCAL);
    if (!g_ts3.dl_opus) {
        fprintf(stderr, "[ts3_client] dlopen libopus: %s\n", TS3BRIDGE_DLERROR());
        TS3BRIDGE_DLCLOSE(g_ts3.dl_ts3); g_ts3.dl_ts3 = NULL;
        return -1;
    }

#define LOAD_TS3(field, sym) \
    if (ts3bridge_load_sym(g_ts3.dl_ts3, (sym), (void**)&g_ts3.field) != 0) return -1

    LOAD_TS3(ts3_init,          "ts3client_initClientLib");
    LOAD_TS3(ts3_destroy,       "ts3client_destroyClientLib");
    LOAD_TS3(ts3_spawn,         "ts3client_spawnNewServerConnectionHandler");
    LOAD_TS3(ts3_destroyHandler,"ts3client_destroyServerConnectionHandler");
    LOAD_TS3(ts3_createIdentity,"ts3client_createIdentity");
    LOAD_TS3(ts3_freeMemory,    "ts3client_freeMemory");
    LOAD_TS3(ts3_start,         "ts3client_startConnection");
    LOAD_TS3(ts3_stop,          "ts3client_stopConnection");
    LOAD_TS3(ts3_getClientID,   "ts3client_getClientID");
    LOAD_TS3(ts3_getStatus,     "ts3client_getConnectionStatus");
    LOAD_TS3(ts3_move,          "ts3client_requestClientMove");
    LOAD_TS3(ts3_setSelfVar,    "ts3client_setClientSelfVariableAsString");
    LOAD_TS3(ts3_flushSelf,     "ts3client_flushClientSelfUpdates");
    LOAD_TS3(ts3_openCapture,   "ts3client_openCaptureDevice");
    LOAD_TS3(ts3_closeCapture,  "ts3client_closeCaptureDevice");
    LOAD_TS3(ts3_acquireCapture,"ts3client_acquireCustomCaptureData");
#undef LOAD_TS3

#define LOAD_OPUS(field, sym) \
    if (ts3bridge_load_sym(g_ts3.dl_opus, (sym), (void**)&g_ts3.field) != 0) return -1

    LOAD_OPUS(opus_get_size, "opus_decoder_get_size");
    LOAD_OPUS(opus_create,   "opus_decoder_create");
    LOAD_OPUS(opus_decode,   "opus_decode");
    LOAD_OPUS(opus_destroy,  "opus_decoder_destroy");
#undef LOAD_OPUS

    return 0;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_init — initialize SDK + Opus decoder + connection notification pipe
 * sdkResourcesDir: directory containing TS3 SDK resource files.
 * Returns 0 on success, -1 on error.
 * ─────────────────────────────────────────────────────────────────────── */

static int ts3bridge_init(const char* sdkResourcesDir) {
    /* Notification pipe: SDK callback writes a byte here; Go reads it. */
    if (pipe(g_ts3.conn_pipe) != 0) {
        perror("[ts3_client] pipe");
        return -1;
    }

    ring_init(&g_ts3.ring);

    /* Create Opus decoder (48 kHz, mono). */
    int opus_err = 0;
    int decoder_size = g_ts3.opus_get_size(TS3BRIDGE_PCM_CHANNELS);
    g_ts3.opus_decoder = malloc(decoder_size);
    if (!g_ts3.opus_decoder) {
        fprintf(stderr, "[ts3_client] opus decoder alloc failed\n");
        return -1;
    }
    g_ts3.opus_decoder = g_ts3.opus_create(TS3BRIDGE_PCM_RATE,
                                             TS3BRIDGE_PCM_CHANNELS,
                                             &opus_err);
    if (!g_ts3.opus_decoder || opus_err != 0) {
        fprintf(stderr, "[ts3_client] opus_decoder_create: error %d\n", opus_err);
        return -1;
    }

    /* SDK callback struct: only the two callbacks we implement. */
    static struct TS3ClientUIFunctions cbs;
    memset(&cbs, 0, sizeof(cbs));
    cbs.onConnectStatusChangeEvent           = ts3BridgeConnectStatusCallback;
    cbs.onCustomDeviceCaptureDataIsAvailable = ts3bridge_capture_adapter;

    ts3_error err = g_ts3.ts3_init(&cbs, NULL, TS3_LOG_NONE, "", sdkResourcesDir);
    if (err != TS3_ERROR_OK) {
        fprintf(stderr, "[ts3_client] ts3client_initClientLib error %u\n", err);
        return -1;
    }

    err = g_ts3.ts3_spawn(0, &g_ts3.scHandlerID);
    if (err != TS3_ERROR_OK) {
        fprintf(stderr, "[ts3_client] ts3client_spawnNewServerConnectionHandler error %u\n", err);
        return -1;
    }

    return 0;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_connect — start a TS3 connection; block until established.
 * Returns client ID string (caller must free) on success, NULL on error.
 * serverPw is a secret and must not be logged.
 * ─────────────────────────────────────────────────────────────────────── */

static char* ts3bridge_connect(const char* host, unsigned int port,
                                const char* nickname, const char* identityPath,
                                const char* serverPw) {
    /* Create or load identity. */
    char* identity = NULL;
    if (identityPath && identityPath[0]) {
        /* Load identity from file — read into a malloc'd buffer. */
        FILE* f = fopen(identityPath, "r");
        if (f) {
            fseek(f, 0, SEEK_END);
            long sz = ftell(f);
            rewind(f);
            if (sz > 0 && sz < 65536) {
                identity = (char*)malloc(sz + 1);
                if (identity) {
                    size_t nr = fread(identity, 1, (size_t)sz, f);
                    identity[nr] = '\0';
                }
            }
            fclose(f);
        }
    }
    int created_identity = 0;
    if (!identity) {
        ts3_error err = g_ts3.ts3_createIdentity(&identity);
        if (err != TS3_ERROR_OK || !identity) {
            fprintf(stderr, "[ts3_client] ts3client_createIdentity error %u\n", err);
            return NULL;
        }
        created_identity = 1;
    }

    ts3_error err = g_ts3.ts3_start(g_ts3.scHandlerID,
                                    identity,
                                    host, port,
                                    nickname,
                                    NULL,   /* no default channel via connect */
                                    NULL,   /* no default channel password */
                                    serverPw ? serverPw : "");
    if (created_identity) {
        g_ts3.ts3_freeMemory(identity);
    } else {
        free(identity);
    }
    if (err != TS3_ERROR_OK) {
        /* Do not log serverPw — error code only. */
        fprintf(stderr, "[ts3_client] ts3client_startConnection error %u\n", err);
        return NULL;
    }

    /* Wait for STATUS_CONNECTION_ESTABLISHED via the pipe (written by callback).
     * Timeout: 15 seconds. */
    struct timeval tv = { .tv_sec = 15, .tv_usec = 0 };
    fd_set fds;
    FD_ZERO(&fds);
    FD_SET(g_ts3.conn_pipe[0], &fds);
    int sel = select(g_ts3.conn_pipe[0] + 1, &fds, NULL, NULL, &tv);
    if (sel <= 0) {
        fprintf(stderr, "[ts3_client] connect timeout or select error\n");
        return NULL;
    }
    char status_byte = 0;
    if (read(g_ts3.conn_pipe[0], &status_byte, 1) < 1) {
        fprintf(stderr, "[ts3_client] connect: pipe read failed\n");
        return NULL;
    }
    if (status_byte != TS3_STATUS_CONNECTION_ESTABLISHED) {
        fprintf(stderr, "[ts3_client] connect failed, status=%d\n", (int)status_byte);
        return NULL;
    }

    /* Open custom capture device for audio injection. */
    err = g_ts3.ts3_openCapture(g_ts3.scHandlerID, "custom", TS3BRIDGE_CUSTOM_DEVICE);
    if (err != TS3_ERROR_OK) {
        fprintf(stderr, "[ts3_client] openCaptureDevice error %u\n", err);
        /* Non-fatal for connect — audio may just not work. */
    }

    anyID clientID = 0;
    err = g_ts3.ts3_getClientID(g_ts3.scHandlerID, &clientID);
    if (err != TS3_ERROR_OK) {
        fprintf(stderr, "[ts3_client] getClientID error %u\n", err);
        return NULL;
    }

    /* Return client ID as a string. */
    char* result = (char*)malloc(32);
    if (result) snprintf(result, 32, "%u", (unsigned int)clientID);
    return result;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_join_channel — move client to channelID.
 * channelPw is a secret and must not be logged.
 * Returns 0 on success, -1 on error.
 * ─────────────────────────────────────────────────────────────────────── */

static int ts3bridge_join_channel(uint64 channelID, const char* channelPw) {
    anyID clientID = 0;
    ts3_error err = g_ts3.ts3_getClientID(g_ts3.scHandlerID, &clientID);
    if (err != TS3_ERROR_OK) {
        fprintf(stderr, "[ts3_client] getClientID error %u\n", err);
        return -1;
    }
    err = g_ts3.ts3_move(g_ts3.scHandlerID, clientID, channelID,
                          channelPw ? channelPw : "", "");
    if (err != TS3_ERROR_OK) {
        /* Do not log channelPw — error code only. */
        fprintf(stderr, "[ts3_client] requestClientMove error %u\n", err);
        return -1;
    }
    return 0;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_set_nickname — change the bot's display name.
 * Returns 0 on success, -1 on error.
 * ─────────────────────────────────────────────────────────────────────── */

static int ts3bridge_set_nickname(const char* nickname) {
    ts3_error err = g_ts3.ts3_setSelfVar(g_ts3.scHandlerID,
                                          TS3_CLIENT_NICKNAME, nickname);
    if (err != TS3_ERROR_OK) {
        fprintf(stderr, "[ts3_client] setClientSelfVariableAsString error %u\n", err);
        return -1;
    }
    err = g_ts3.ts3_flushSelf(g_ts3.scHandlerID, "");
    if (err != TS3_ERROR_OK) {
        fprintf(stderr, "[ts3_client] flushClientSelfUpdates error %u\n", err);
        return -1;
    }
    return 0;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_push_opus_frame — decode Opus and push PCM into the ring buffer.
 * Called from Go on each send_opus_frame request.
 * frame/frameLen: raw Opus packet bytes (NOT base64).
 * Returns 0 on success, -1 on decode error.
 * ─────────────────────────────────────────────────────────────────────── */

static int ts3bridge_push_opus_frame(const unsigned char* frame, int frameLen) {
    short pcm[TS3BRIDGE_FRAME_SAMPLES];
    int samples = g_ts3.opus_decode(g_ts3.opus_decoder, frame, frameLen,
                                     pcm, TS3BRIDGE_FRAME_SAMPLES, 0);
    if (samples < 0) {
        fprintf(stderr, "[ts3_client] opus_decode error %d\n", samples);
        return -1;
    }
    ring_push(&g_ts3.ring, pcm, samples);
    return 0;
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_pop_pcm — called by the capture callback to fill the SDK buffer.
 * ─────────────────────────────────────────────────────────────────────── */

static int ts3bridge_pop_pcm(short* dst, int n) {
    return ring_pop(&g_ts3.ring, dst, n);
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_disconnect — stop the TS3 connection and clean up.
 * ─────────────────────────────────────────────────────────────────────── */

static void ts3bridge_disconnect(void) {
    if (g_ts3.scHandlerID) {
        g_ts3.ts3_closeCapture(g_ts3.scHandlerID);
        g_ts3.ts3_stop(g_ts3.scHandlerID, "");
        g_ts3.ts3_destroyHandler(g_ts3.scHandlerID);
        g_ts3.scHandlerID = 0;
    }
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_shutdown — destroy SDK, decoder, and unload libraries.
 * ─────────────────────────────────────────────────────────────────────── */

static void ts3bridge_shutdown(void) {
    ts3bridge_disconnect();
    if (g_ts3.opus_decoder) {
        g_ts3.opus_destroy(g_ts3.opus_decoder);
        g_ts3.opus_decoder = NULL;
    }
    if (g_ts3.ts3_destroy) g_ts3.ts3_destroy();
    ring_destroy(&g_ts3.ring);
    if (g_ts3.conn_pipe[0]) { close(g_ts3.conn_pipe[0]); g_ts3.conn_pipe[0] = 0; }
    if (g_ts3.conn_pipe[1]) { close(g_ts3.conn_pipe[1]); g_ts3.conn_pipe[1] = 0; }
    if (g_ts3.dl_opus) { TS3BRIDGE_DLCLOSE(g_ts3.dl_opus); g_ts3.dl_opus = NULL; }
    if (g_ts3.dl_ts3)  { TS3BRIDGE_DLCLOSE(g_ts3.dl_ts3);  g_ts3.dl_ts3  = NULL; }
    memset(&g_ts3, 0, sizeof(g_ts3));
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_notify_connect_status — write status byte to the pipe.
 * Called from Go (ts3BridgeConnectStatusCallback → bridgeConnectStatusChanged).
 * ─────────────────────────────────────────────────────────────────────── */

static void ts3bridge_notify_connect_status(int status) {
    if (g_ts3.conn_pipe[1]) {
        char b = (char)status;
        if (write(g_ts3.conn_pipe[1], &b, 1) < 0) {
            /* notification pipe write failed — connect timeout will handle */
        }
    }
}

/* ──────────────────────────────────────────────────────────────────────────
 * ts3bridge_get_connection_status — poll current status.
 * ─────────────────────────────────────────────────────────────────────── */

static int ts3bridge_get_connection_status(void) {
    int status = TS3_STATUS_DISCONNECTED;
    if (g_ts3.ts3_getStatus && g_ts3.scHandlerID)
        g_ts3.ts3_getStatus(g_ts3.scHandlerID, &status);
    return status;
}

/* ts3bridge_acquire_custom_capture — forward to SDK (used in capture callback) */
static ts3_error ts3bridge_acquire_custom_capture(const char* deviceName,
                                                   short** buf, int* samples) {
    if (!g_ts3.ts3_acquireCapture) return 1;
    return g_ts3.ts3_acquireCapture(deviceName, buf, samples);
}

#endif /* EASYWI_TS3_CLIENT_H */
