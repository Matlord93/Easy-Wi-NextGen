package main

import (
	"encoding/binary"
	"hash/crc32"
)

// wrapOpusInOgg wraps a single Opus frame in a minimal Ogg/Opus stream so
// ffmpeg can decode it. This produces a valid but minimal container with:
//   - ID header page (serial 0, granule 0, beginning of stream + end of stream)
//   - Comment header page
//   - Audio data page
//
// Sample rate is fixed at 48000 Hz, mono. durationMs is used for the granule.
func wrapOpusInOgg(frame []byte, durationMs int) []byte {
	const sampleRate = 48000
	granule := uint64(sampleRate * durationMs / 1000)
	serial := uint32(0x45574954) // "EWIT"

	var buf []byte

	// ID header: "OpusHead" magic, version 1, 1 channel, 80ms pre-skip, 48kHz, gain 0, mono
	idHeader := []byte{
		'O', 'p', 'u', 's', 'H', 'e', 'a', 'd',
		1,    // version
		1,    // channels
		0, 0, // pre-skip (little-endian uint16)
		0x80, 0xBB, 0, 0, // input sample rate = 48000 LE
		0, 0, // output gain
		0, // channel mapping family
	}
	buf = append(buf, oggPage(idHeader, serial, 0, 0, oggFlagBOS)...)

	// Comment header: "OpusTags" + minimal vendor string
	commentHeader := buildOpusCommentHeader()
	buf = append(buf, oggPage(commentHeader, serial, 0, 1, 0)...)

	// Audio data page
	buf = append(buf, oggPage(frame, serial, granule, 2, oggFlagEOS)...)

	return buf
}

const (
	oggFlagBOS = 0x02
	oggFlagEOS = 0x04
)

func oggPage(data []byte, serial uint32, granule uint64, seqno uint32, flags byte) []byte {
	// Build the lacing table: each segment is at most 255 bytes
	segments := []byte{}
	remaining := len(data)
	for remaining >= 255 {
		segments = append(segments, 255)
		remaining -= 255
	}
	segments = append(segments, byte(remaining))

	headerSize := 27 + len(segments)
	page := make([]byte, headerSize+len(data))

	copy(page[0:4], []byte("OggS")) // capture pattern
	page[4] = 0                     // stream structure version
	page[5] = flags                 // header type
	binary.LittleEndian.PutUint64(page[6:14], granule)
	binary.LittleEndian.PutUint32(page[14:18], serial)
	binary.LittleEndian.PutUint32(page[18:22], seqno)
	// checksum placeholder [22:26] = 0
	page[26] = byte(len(segments))
	copy(page[27:], segments)
	copy(page[headerSize:], data)

	checksum := crc32.Checksum(page, oggCRC32Table)
	binary.LittleEndian.PutUint32(page[22:26], checksum)
	return page
}

func buildOpusCommentHeader() []byte {
	vendor := "easywi-bridge"
	buf := make([]byte, 0, 16+len(vendor))
	buf = append(buf, 'O', 'p', 'u', 's', 'T', 'a', 'g', 's')
	vlen := make([]byte, 4)
	binary.LittleEndian.PutUint32(vlen, uint32(len(vendor)))
	buf = append(buf, vlen...)
	buf = append(buf, vendor...)
	buf = append(buf, 0, 0, 0, 0) // user comment list length = 0
	return buf
}

// oggCRC32Table is the CRC32 table used by Ogg (generator polynomial 0x04C11DB7).
var oggCRC32Table = crc32.MakeTable(0x04C11DB7)
