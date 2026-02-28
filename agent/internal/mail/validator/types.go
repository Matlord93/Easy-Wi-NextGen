package validator

import "time"

type Status string

type Severity string

const (
	StatusOK      Status = "ok"
	StatusWarning Status = "warning"
	StatusError   Status = "error"

	SeverityInfo     Severity = "info"
	SeverityWarning  Severity = "warning"
	SeverityCritical Severity = "critical"
)

type Finding struct {
	Check         string    `json:"check"`
	Status        Status    `json:"status"`
	Severity      Severity  `json:"severity"`
	Details       string    `json:"details"`
	ObservedValue string    `json:"observed_value"`
	ExpectedValue string    `json:"expected_value"`
	Timestamp     time.Time `json:"timestamp"`
}

type DomainValidationRequest struct {
	Domain            string        `json:"domain"`
	Selector          string        `json:"selector"`
	ExpectedMXTargets []string      `json:"expected_mx_targets,omitempty"`
	KnownIPs          []string      `json:"known_ips,omitempty"`
	TLSHost           string        `json:"tls_host,omitempty"`
	TLSPort           int           `json:"tls_port,omitempty"`
	MTASTSEnabled     bool          `json:"mta_sts_enabled,omitempty"`
	Timeout           time.Duration `json:"timeout,omitempty"`
}

type DomainValidationResult struct {
	Domain           string    `json:"domain"`
	DkimStatus       Status    `json:"dkim_status"`
	SpfStatus        Status    `json:"spf_status"`
	DmarcStatus      Status    `json:"dmarc_status"`
	MxStatus         Status    `json:"mx_status"`
	TLSStatus        Status    `json:"tls_status"`
	DnsLastCheckedAt time.Time `json:"dns_last_checked_at"`
	Findings         []Finding `json:"findings"`
}
