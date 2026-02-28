package configrender

import "time"

type Snapshot struct {
	Revision     string
	Generated    time.Time
	Domains      []Domain
	Users        []User
	Aliases      []Alias
	Forwardings  []Forwarding
	Policies     []Policy
	RateLimits   []RateLimit
	DKIMKeys     []DKIMKeyMetadata
	TrustedHosts []string
}

type Domain struct {
	Name        string
	MailEnabled bool
}

type User struct {
	Address      string
	PasswordHash string
	MailboxPath  string
	QuotaBytes   int64
	Enabled      bool
}

type Alias struct {
	Address      string
	Destinations []string
	Enabled      bool
}

type Forwarding struct {
	Source      string
	Destination string
	Enabled     bool
}

type Policy struct {
	Name  string
	Value string
}

type RateLimit struct {
	Address              string
	MaxMailsPerHour      int
	MaxRecipientsPerMail int
}

type DKIMKeyMetadata struct {
	Domain         string
	Selector       string
	PrivateKeyPath string
	Enabled        bool
}

type FileSpec struct {
	Service string
	Path    string
	Body    []byte
}

type RenderBundle struct {
	Revision string
	Files    []FileSpec
}

type ApplyResult struct {
	Revision       string
	ActivatedAt    time.Time
	FilesActivated []string
	Health         map[string]string
}

type ApplyErrorClass string

const (
	ErrClassRender   ApplyErrorClass = "render_error"
	ErrClassWrite    ApplyErrorClass = "write_error"
	ErrClassValidate ApplyErrorClass = "validate_error"
	ErrClassActivate ApplyErrorClass = "activate_error"
	ErrClassHealth   ApplyErrorClass = "healthcheck_error"
	ErrClassRollback ApplyErrorClass = "rollback_error"
)

type ApplyError struct {
	Class   ApplyErrorClass
	Service string
	Code    string
	Message string
}

func (e *ApplyError) Error() string {
	if e == nil {
		return ""
	}
	return e.Message
}
