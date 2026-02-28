package crypto

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

type jwtHeader struct {
	Alg string `json:"alg"`
	Typ string `json:"typ"`
}

type JWTClaims struct {
	Iss string `json:"iss"`
	Sub string `json:"sub"`
	Aud string `json:"aud"`
	Iat int64  `json:"iat"`
	Exp int64  `json:"exp"`
	Jti string `json:"jti"`
}

func SignHS256JWT(secret string, claims JWTClaims) (string, error) {
	header := jwtHeader{Alg: "HS256", Typ: "JWT"}
	h, err := json.Marshal(header)
	if err != nil {
		return "", fmt.Errorf("marshal jwt header: %w", err)
	}
	c, err := json.Marshal(claims)
	if err != nil {
		return "", fmt.Errorf("marshal jwt claims: %w", err)
	}

	signingInput := base64.RawURLEncoding.EncodeToString(h) + "." + base64.RawURLEncoding.EncodeToString(c)
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(signingInput))
	sig := base64.RawURLEncoding.EncodeToString(mac.Sum(nil))

	return signingInput + "." + sig, nil
}

func BuildAgentJWT(secret, agentID, issuer, audience, jti string, now time.Time, ttl time.Duration) (string, error) {
	if ttl <= 0 {
		ttl = time.Minute
	}
	claims := JWTClaims{
		Iss: issuer,
		Sub: agentID,
		Aud: audience,
		Iat: now.UTC().Unix(),
		Exp: now.UTC().Add(ttl).Unix(),
		Jti: strings.TrimSpace(jti),
	}

	return SignHS256JWT(secret, claims)
}
