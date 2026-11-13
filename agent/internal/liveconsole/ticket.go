package liveconsole

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/url"
	"regexp"
	"strings"
	"time"
)

const ticketDomain = "easywi-console-live-v1."

var noncePattern = regexp.MustCompile(`^[0-9a-f]{32}$`)

type Claims struct {
	Version  int    `json:"v"`
	Scope    string `json:"scope"`
	Agent    string `json:"agent"`
	Instance string `json:"instance"`
	Actor    string `json:"actor"`
	Origin   string `json:"origin"`
	Issued   int64  `json:"iat"`
	Expires  int64  `json:"exp"`
	Until    int64  `json:"until"`
	Nonce    string `json:"nonce"`
}

func verifyTicket(token, secret, agent, instance, origin string, now time.Time) (Claims, error) {
	var c Claims
	bad := errors.New("invalid console authorization")
	if len(token) > 4096 || secret == "" {
		return c, bad
	}
	parts := strings.Split(token, ".")
	if len(parts) != 2 {
		return c, bad
	}
	sig, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil {
		return c, bad
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(ticketDomain + parts[0]))
	if !hmac.Equal(sig, mac.Sum(nil)) {
		return c, bad
	}
	raw, err := base64.RawURLEncoding.DecodeString(parts[0])
	if err != nil {
		return c, bad
	}
	dec := json.NewDecoder(strings.NewReader(string(raw)))
	dec.DisallowUnknownFields()
	if dec.Decode(&c) != nil {
		return c, bad
	}
	u, err := url.Parse(c.Origin)
	if err != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || u.Path != "" || (u.Scheme != "http" && u.Scheme != "https") {
		return c, bad
	}
	t := now.Unix()
	if c.Version != 1 || c.Scope != "gameserver.console.read" || c.Agent != agent || c.Instance != instance || c.Actor == "" || c.Origin != origin || !noncePattern.MatchString(c.Nonce) || c.Issued > t+5 || c.Issued < t-35 || c.Expires <= t || c.Expires > c.Issued+30 || c.Until <= t || c.Until > c.Issued+300 {
		return c, bad
	}
	return c, nil
}
