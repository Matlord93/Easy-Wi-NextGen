package main

import (
	"testing"

	"easywi/agent/internal/jobs"

	"github.com/DATA-DOG/go-sqlmock"
)

func TestParseDatabaseRequestAcceptsPostgresAlias(t *testing.T) {
	req, err := parseDatabaseRequest(jobs.Job{Payload: map[string]any{
		"engine":       "postgres",
		"host":         "127.0.0.1",
		"port":         "5432",
		"database":     "customerdb",
		"username":     "customerusr",
		"admin_user":   "svc_admin",
		"admin_secret": "secret",
	}})
	if err != nil {
		t.Fatalf("unexpected err: %v", err)
	}
	if req.Engine != "postgresql" {
		t.Fatalf("expected normalized engine postgresql, got %s", req.Engine)
	}
}

func TestPostgresEnsureDatabaseAndUserAppliesRoleCreateAndGrant(t *testing.T) {
	db, mock, err := sqlmock.New()
	if err != nil {
		t.Fatalf("sqlmock: %v", err)
	}
	defer db.Close()

	req := databaseRequest{Database: "custdb", Username: "custusr"}
	mock.ExpectQuery("SELECT 1 FROM pg_roles").WithArgs("custusr").WillReturnRows(sqlmock.NewRows([]string{"?"}))
	mock.ExpectExec("CREATE ROLE").WillReturnResult(sqlmock.NewResult(0, 1))
	mock.ExpectQuery("SELECT 1 FROM pg_database").WithArgs("custdb").WillReturnRows(sqlmock.NewRows([]string{"?"}))
	mock.ExpectExec("CREATE DATABASE").WillReturnResult(sqlmock.NewResult(0, 1))
	mock.ExpectExec("GRANT ALL PRIVILEGES ON DATABASE").WillReturnResult(sqlmock.NewResult(0, 1))

	if err := postgresEnsureDatabaseAndUser(db, req, "newSecret"); err != nil {
		t.Fatalf("ensure failed: %v", err)
	}
	if err := mock.ExpectationsWereMet(); err != nil {
		t.Fatalf("expectations: %v", err)
	}
}
