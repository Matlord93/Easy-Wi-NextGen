package main

import (
	"database/sql"
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
	t.Cleanup(func() {
		if err := mock.ExpectationsWereMet(); err != nil {
			t.Fatalf("expectations: %v", err)
		}
	})

	dbScoped, scopedMock, err := sqlmock.New()
	if err != nil {
		t.Fatalf("sqlmock scoped: %v", err)
	}
	t.Cleanup(func() {
		if err := scopedMock.ExpectationsWereMet(); err != nil {
			t.Fatalf("scoped expectations: %v", err)
		}
	})

	oldScoped := openPostgresScopedConnectionFn
	openPostgresScopedConnectionFn = func(databaseRequest, string) (*sql.DB, func(), error) {
		return dbScoped, func() {}, nil
	}
	t.Cleanup(func() {
		openPostgresScopedConnectionFn = oldScoped
	})

	req := databaseRequest{Database: "custdb", Username: "custusr"}
	mock.ExpectQuery("SELECT 1 FROM pg_roles").WithArgs("custusr").WillReturnRows(sqlmock.NewRows([]string{"?"}))
	mock.ExpectExec("CREATE ROLE").WithArgs("newSecret").WillReturnResult(sqlmock.NewResult(0, 1))
	mock.ExpectQuery("SELECT 1 FROM pg_database").WithArgs("custdb").WillReturnRows(sqlmock.NewRows([]string{"?"}))
	mock.ExpectExec("CREATE DATABASE").WillReturnResult(sqlmock.NewResult(0, 1))
	mock.ExpectExec("GRANT ALL PRIVILEGES ON DATABASE").WillReturnResult(sqlmock.NewResult(0, 1))

	scopedMock.ExpectExec("GRANT USAGE, CREATE ON SCHEMA public").WillReturnResult(sqlmock.NewResult(0, 1))
	scopedMock.ExpectExec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT").WillReturnResult(sqlmock.NewResult(0, 1))
	scopedMock.ExpectExec("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES").WillReturnResult(sqlmock.NewResult(0, 1))

	if err := postgresEnsureDatabaseAndUser(db, req, "newSecret"); err != nil {
		t.Fatalf("ensure failed: %v", err)
	}
}
